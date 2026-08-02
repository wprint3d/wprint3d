<?php

namespace Tests\Unit;

use App\Jobs\PrintGcode;
use App\Libraries\Serial;
use App\Models\Configuration;
use App\Models\Printer;
use App\Plugins\PluginHookDispatcher;
use App\Support\FakeSerial\FakeSerialEmulator;
use App\Support\FakeSerial\FakeSerialManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PrintGcodeFailureStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Configuration::query()->delete();
        Configuration::create(['key' => 'debugSerial', 'value' => false]);
        Configuration::create(['key' => 'terminalMaxLines', 'value' => 100]);
        Configuration::create(['key' => 'lastSeenThresholdSecs', 'value' => 7]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(FakeSerialManager::class);
        Configuration::query()->delete();

        Mockery::close();

        parent::tearDown();
    }

    public function test_failed_print_keeps_recovery_file_but_clears_active_job_state(): void
    {
        $fakeSerialCache = new Repository(new ArrayStore);

        app()->instance(FakeSerialManager::class, new FakeSerialManager(
            cache: $fakeSerialCache,
            emulator: new FakeSerialEmulator,
            settings: [
                'enabled' => true,
                'node' => 'PRINT_FAILURE_TEST',
                'baudRate' => 115200,
                'supportedBaudRates' => [115200],
                'logMaxEntries' => 100,
            ],
        ));

        $serialPluginHooks = [
            'serial.command.before_send' => static fn (array $context = []): array => [],
            'serial.line.received' => static fn (array $context = []): array => [],
            'serial.command.response_received' => static fn (array $context = []): array => [],
        ];

        $serial = new Serial(
            fileName: 'PRINT_FAILURE_TEST',
            baudRate: 115200,
            timeout: 1,
            pluginHooks: $serialPluginHooks
        );
        $serial->query('M104 S200');
        $serial->query('M140 S50');
        $serial->close();

        Mockery::mock('alias:App\Events\PrintJobFailed')
            ->shouldReceive('dispatch')
            ->once()
            ->with('printer-1');

        Mockery::mock('alias:App\Events\PrintJobFinished')
            ->shouldReceive('dispatch')
            ->once()
            ->with('printer-1');

        app()->instance(PluginHookDispatcher::class, new class
        {
            public array $calls = [];

            public function dispatch(string $hook, array $context = []): array
            {
                $this->calls[] = compact('hook', 'context');

                return [];
            }
        });

        $printer = new class extends Printer
        {
            public string $_id;

            public bool $hasActiveJob;

            public bool $lastJobHasFailed;

            public ?string $activeFile;

            public ?int $lastLine;

            public ?string $node;

            public int $baudRate;

            public bool $saved = false;

            public function __construct() {}

            public function save(array $options = []): bool
            {
                $this->saved = true;

                return true;
            }

            public function setCurrentLine(int $line): bool
            {
                return true;
            }

            public function setMaxLine(int $line): bool
            {
                return true;
            }

            public function setLastCommand(?string $command): bool
            {
                return true;
            }

            public function resume(): bool
            {
                return true;
            }
        };

        $printer->_id = 'printer-1';
        $printer->hasActiveJob = true;
        $printer->lastJobHasFailed = false;
        $printer->activeFile = 'test_connectivity.gcode';
        $printer->lastLine = 39;
        $printer->node = 'PRINT_FAILURE_TEST';
        $printer->baudRate = 115200;

        $job = (new \ReflectionClass(PrintGcode::class))->newInstanceWithoutConstructor();

        \Closure::bind(function () use ($job, $printer, $serialPluginHooks) {
            $job->printer = $printer;
            $job->filePath = 'test_connectivity.gcode';
            $job->uid = 'job-uid';
            $job->shouldRecord = false;
            $job->recordableCameras = [];
            $job->commandTimeoutSecs = 1;
            $job->serialPluginHooks = $serialPluginHooks;
        }, null, PrintGcode::class)();

        $job->failed(new RuntimeException('serial timeout'));
        gc_collect_cycles();

        $this->assertTrue($printer->saved);
        $this->assertTrue($printer->lastJobHasFailed);
        $this->assertFalse($printer->hasActiveJob);
        $this->assertSame('test_connectivity.gcode', $printer->activeFile);
        $this->assertSame(39, $printer->lastLine);

        $state = $fakeSerialCache->get('fake-serial:PRINT_FAILURE_TEST:state');

        $this->assertSame(0.0, $state['hotend']['target']);
        $this->assertSame(0.0, $state['bed']['target']);
    }
}
