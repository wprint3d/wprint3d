<?php

namespace Tests\Unit;

use App\Enums\BackupInterval;
use App\Jobs\PrintGcode;
use App\Libraries\Serial;
use App\Models\Configuration;
use App\Models\File;
use App\Models\Printer;
use App\Models\User;
use App\Plugins\PluginHookDispatcher;
use App\Services\PrintExecutionState;
use App\Support\FakeSerial\FakeSerialEmulator;
use App\Support\FakeSerial\FakeSerialManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrintGcodeResumeTest extends TestCase
{
    private ?Printer $printer = null;

    private ?User $owner = null;

    private Repository $fakeSerialCache;

    private array $serialPluginHooks;

    private array $filePaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.print_execution_store' => 'array']);
        Cache::store('array')->flush();
        Configuration::query()->delete();

        foreach ([
            'debugSerial' => false,
            'terminalMaxLines' => 100,
            'lastSeenThresholdSecs' => 7,
            'runningTimeoutSecs' => 10,
            'commandTimeoutSecs' => 2,
            'lastSeenPollIntervalSecs' => 2,
            'jobBackupInterval' => BackupInterval::NEVER,
            'streamMaxLengthBytes' => 256,
            'jobStatisticsQueryIntervalSecs' => 3600,
            'autoSerialIntervalSecs' => 0,
            'negotiationMaxRetries' => 0,
            'negotiationTimeoutSecs' => 3,
        ] as $key => $value) {
            Configuration::create(compact('key', 'value'));
        }

        Storage::fake('gcode');
        Event::fake();
        $this->fakeSerialCache = new Repository(new ArrayStore);
        app()->instance(FakeSerialManager::class, new FakeSerialManager(
            cache: $this->fakeSerialCache,
            emulator: new FakeSerialEmulator,
            settings: [
                'enabled' => true,
                'node' => 'PRINT_RESUME_TEST',
                'baudRate' => 115200,
                'supportedBaudRates' => [115200],
                'logMaxEntries' => 500,
            ],
        ));
        app()->instance(PluginHookDispatcher::class, new class
        {
            public function dispatch(string $hook, array $context = []): array
            {
                return [];
            }
        });
        $this->serialPluginHooks = [
            'serial.command.before_send' => static fn (array $context = []): array => [],
            'serial.line.received' => static fn (array $context = []): array => [],
            'serial.command.response_received' => static fn (array $context = []): array => [],
        ];
    }

    protected function tearDown(): void
    {
        File::whereIn('path', $this->filePaths)->delete();
        $this->printer?->delete();

        $this->owner?->delete();
        Configuration::query()->delete();
        app()->forgetInstance(FakeSerialManager::class);
        app()->forgetInstance(PluginHookDispatcher::class);

        parent::tearDown();
    }

    public function test_worker_restart_validates_the_printer_and_skips_confirmed_source_commands(): void
    {
        $filePath = 'partial-burnout-resume.gcode';
        $this->filePaths[] = $filePath;
        Storage::disk('gcode')->put($filePath, implode(PHP_EOL, [
            'G21',
            'G1 X5 Y10 Z0.2 E2 F1200',
            'G1 X11 Y20 Z0.4 E4 F1200',
            '',
        ]));
        [$this->owner, $this->printer] = $this->persistScenario($filePath);
        $executionState = app(PrintExecutionState::class);
        $executionState->begin($this->printer, $this->owner, 'resume-job-uid', 'resume-token');

        $serial = $this->serial();
        $position = movementToXYZE($serial->query('M114'));
        $serial->query('M105');
        $executionState->markReady(
            (string) $this->printer->_id,
            'resume-token',
            $position,
            $this->printer->getStatistics()
        );

        foreach (['G21', 'G1 X5 Y10 Z0.2 E2 F1200'] as $command) {
            $executionState->preparePending(
                (string) $this->printer->_id,
                'resume-token',
                $command,
                true
            );
            $serial->query($command);
            $executionState->commitPending(
                (string) $this->printer->_id,
                'resume-token',
                $this->printer->getStatistics()
            );
        }
        $serial->close();
        unset($serial);

        $job = new PrintGcode(
            $this->owner,
            (string) $this->printer->_id,
            'resume-job-uid',
            'resume-token'
        );
        $serialPluginHooks = $this->serialPluginHooks;
        \Closure::bind(function () use ($job, $serialPluginHooks) {
            $job->serialPluginHooks = $serialPluginHooks;
        }, null, PrintGcode::class)();

        $job->handle();
        unset($job);
        gc_collect_cycles();

        $this->printer->refresh();
        $inputCommands = array_values(array_map(
            static fn (array $entry): string => $entry['message'],
            array_filter(
                $this->fakeSerialCache->get('fake-serial:log', []),
                static fn (array $entry): bool => $entry['direction'] === 'input'
            )
        ));

        $this->assertSame(1, count(array_filter(
            $inputCommands,
            static fn (string $command): bool => $command === 'G1 X5 Y10 Z0.2 E2 F1200'
        )));
        $this->assertSame(1, count(array_filter(
            $inputCommands,
            static fn (string $command): bool => $command === 'G1 X11 Y20 Z0.4 E4 F1200'
        )));
        $this->assertFalse($this->printer->hasActiveJob);
        $this->assertNull($this->printer->activeFile);
        $this->assertNull($this->printer->activePrintExecution);
        $this->assertNull(File::where('path', $filePath)->first());
    }

    public function test_new_print_establishes_an_identity_checkpoint_before_streaming_source_commands(): void
    {
        $filePath = 'partial-burnout-new-print.gcode';
        $this->filePaths[] = $filePath;
        Storage::disk('gcode')->put($filePath, "G1 X7 Y8 Z0.2 E1 F1200\n");
        [$this->owner, $this->printer] = $this->persistScenario($filePath);
        app(PrintExecutionState::class)->begin(
            $this->printer,
            $this->owner,
            'new-print-uid',
            'new-print-token'
        );
        $job = new PrintGcode(
            $this->owner,
            (string) $this->printer->_id,
            'new-print-uid',
            'new-print-token'
        );
        $serialPluginHooks = $this->serialPluginHooks;
        \Closure::bind(function () use ($job, $serialPluginHooks) {
            $job->serialPluginHooks = $serialPluginHooks;
        }, null, PrintGcode::class)();

        $job->handle();
        unset($job);
        gc_collect_cycles();

        $this->printer->refresh();
        $inputCommands = array_values(array_map(
            static fn (array $entry): string => $entry['message'],
            array_filter(
                $this->fakeSerialCache->get('fake-serial:log', []),
                static fn (array $entry): bool => $entry['direction'] === 'input'
            )
        ));
        $printedFile = File::where('path', $filePath)->first();

        $this->assertLessThan(
            array_search('G1 X7 Y8 Z0.2 E1 F1200', $inputCommands, true),
            array_search('M115', $inputCommands, true)
        );
        $this->assertSame(1, $printedFile->prints);
        $this->assertFalse($this->printer->hasActiveJob);
        $this->assertNull($this->printer->activePrintExecution);
    }

    private function serial(): Serial
    {
        return new Serial(
            fileName: 'PRINT_RESUME_TEST',
            baudRate: 115200,
            printerId: (string) $this->printer->_id,
            timeout: 2,
            terminalAutoAppend: false,
            pluginHooks: $this->serialPluginHooks
        );
    }

    private function persistScenario(string $filePath): array
    {
        $owner = new User;
        $owner->name = 'Print Resume Test';
        $owner->email = 'print-resume@example.test';
        $owner->password = 'test-password';
        $owner->settings = [
            'recording' => [
                'enabled' => false,
                'captureInterval' => 10,
            ],
        ];
        $owner->save();

        $printer = new Printer;
        $printer->node = 'PRINT_RESUME_TEST';
        $printer->baudRate = 115200;
        $printer->connected = true;
        $printer->machine = ['uuid' => 'FAKESERIAL-DEV-PRINTER/resume-test'];
        $printer->hasActiveJob = true;
        $printer->lastJobHasFailed = false;
        $printer->activeFile = $filePath;
        $printer->recordableCameras = [];
        $printer->cameras = [];
        $printer->save();

        return [$owner, $printer];
    }
}
