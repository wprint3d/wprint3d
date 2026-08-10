<?php

namespace Tests\Unit;

use App\Events\PrintersMapInProgress;
use App\Events\PrintersMapUpdated;
use App\Models\Configuration;
use App\Models\Printer;
use App\Support\FakeSerial\FakeSerialEmulator;
use App\Support\FakeSerial\FakeSerialManager;
use App\Support\SerialMapperDebouncer;
use Error;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MapSerialPrintersTest extends TestCase
{
    private const NODE = 'MAPPER_TEST';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Configuration::query()->delete();
        Printer::query()->delete();
        Event::fake();

        foreach ([
            'debugSerial' => false,
            'terminalMaxLines' => 100,
            'lastSeenThresholdSecs' => 7,
            'negotiationWaitSecs' => 0,
            'negotiationTimeoutSecs' => 1,
            'negotiationMaxRetries' => 0,
        ] as $key => $value) {
            Configuration::create(['key' => $key, 'value' => $value]);
        }

        config(['app.common_baud_rates' => [115200]]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(FakeSerialManager::class);
        app()->forgetInstance(SerialMapperDebouncer::class);
        Cache::flush();
        Printer::query()->delete();
        Configuration::query()->delete();

        parent::tearDown();
    }

    public function test_it_maps_a_new_fake_serial_printer_and_clears_the_busy_marker(): void
    {
        $this->bindFakeSerial(new FakeSerialEmulator);

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $printer = Printer::firstOrFail();

        $this->assertNotNull($printer->_id);
        $this->assertSame(self::NODE, $printer->node);
        $this->assertSame('fakeSerial', $printer->machine['connectionType']);
        $this->assertTrue($printer->connected);
        $this->assertFalse(Cache::get(config('cache.mapper_busy_key'), false));
    }

    public function test_it_accepts_valid_multibyte_utf8_during_negotiation(): void
    {
        $emulator = new class extends FakeSerialEmulator
        {
            public function transact(string $command, array $state = []): array
            {
                $result = parent::transact($command, $state);

                if ($command === 'M105') {
                    array_unshift($result['lines'], [
                        'text' => 'echo: impresión lista',
                        'delayMs' => 0,
                    ]);
                }

                return $result;
            }
        };

        $this->bindFakeSerial($emulator);

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));
        $this->assertSame(1, Printer::count());
        $this->assertFalse(Cache::get(config('cache.mapper_busy_key'), false));
    }

    public function test_it_clears_the_busy_marker_when_serial_negotiation_throws_an_error(): void
    {
        $emulator = new class extends FakeSerialEmulator
        {
            public function transact(string $command, array $state = []): array
            {
                throw new Error('simulated transport failure');
            }
        };

        $this->bindFakeSerial($emulator);

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));
        $this->assertSame(0, Printer::count());
        $this->assertFalse(Cache::get(config('cache.mapper_busy_key'), false));
    }

    public function test_it_does_not_retry_the_same_baud_rate_forever_when_firmware_information_fails(): void
    {
        $emulator = new class extends FakeSerialEmulator
        {
            public int $firmwareQueries = 0;

            public int $temperatureQueries = 0;

            public function transact(string $command, array $state = []): array
            {
                if ($command === 'M105') {
                    $this->temperatureQueries++;

                    if ($this->temperatureQueries > 1) {
                        throw new Error('unexpected repeated temperature probe');
                    }
                }

                if ($command === 'M115') {
                    $this->firmwareQueries++;

                    throw new Error('simulated firmware information failure');
                }

                return parent::transact($command, $state);
            }
        };

        $this->bindFakeSerial($emulator);

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));
        $this->assertSame(1, $emulator->temperatureQueries);
        $this->assertSame(1, $emulator->firmwareQueries);
        $this->assertSame(0, Printer::count());
        $this->assertFalse(Cache::get(config('cache.mapper_busy_key'), false));
    }

    public function test_it_discards_a_debounced_invocation_superseded_during_the_quiet_window(): void
    {
        $emulator = new class extends FakeSerialEmulator
        {
            public int $queryCount = 0;

            public function transact(string $command, array $state = []): array
            {
                $this->queryCount++;

                return parent::transact($command, $state);
            }
        };

        $this->bindFakeSerial($emulator);
        app()->instance(SerialMapperDebouncer::class, new SerialMapperDebouncer(
            sleep: function (): void {
                Cache::put(config('cache.serial_mapper_debounce_key'), 'newer-request', 600);
            }
        ));

        $this->assertSame(0, Artisan::call('map:serial-printers', [
            'node' => self::NODE,
            '--debounce' => 3,
        ]));

        $this->assertSame(0, $emulator->queryCount);
        $this->assertSame(0, Printer::count());
        $this->assertFalse(Cache::get(config('cache.mapper_busy_key'), false));
        Event::assertNotDispatched(PrintersMapInProgress::class);
        Event::assertNotDispatched(PrintersMapUpdated::class);
    }

    public function test_it_rechecks_the_debounce_token_after_waiting_for_the_mapper_lock(): void
    {
        $emulator = new class extends FakeSerialEmulator
        {
            public int $queryCount = 0;

            public function transact(string $command, array $state = []): array
            {
                $this->queryCount++;

                return parent::transact($command, $state);
            }
        };

        $this->bindFakeSerial($emulator);
        app()->instance(SerialMapperDebouncer::class, new class extends SerialMapperDebouncer
        {
            public function wait(int $seconds): array
            {
                return [true, 'superseded-while-locked'];
            }

            public function isCurrent(?string $token): bool
            {
                return false;
            }
        });

        $this->assertSame(0, Artisan::call('map:serial-printers', [
            'node' => self::NODE,
            '--debounce' => 3,
        ]));

        $this->assertSame(0, $emulator->queryCount);
        $this->assertSame(0, Printer::count());
        $this->assertFalse(Cache::get(config('cache.mapper_busy_key'), false));
        Event::assertNotDispatched(PrintersMapInProgress::class);
        Event::assertNotDispatched(PrintersMapUpdated::class);
    }

    public function test_it_reconnects_a_known_printer_at_its_saved_baud_rate_before_scanning(): void
    {
        config(['app.common_baud_rates' => [9600, 115200]]);

        $manager = $this->bindFakeSerial(new FakeSerialEmulator);

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $logOffset = count($manager->getLog());

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $newMessages = array_column(array_slice($manager->getLog(), $logOffset), 'message');

        $this->assertStringContainsString('Known printer reconnected', Artisan::output());
        $this->assertNotContains(self::NODE.' connected at 9600 baud', $newMessages);
        $this->assertSame(1, Printer::count());
    }

    public function test_it_prioritizes_an_active_printer_baud_rate_when_it_appears_on_a_new_node(): void
    {
        config(['app.common_baud_rates' => [9600, 115200, 250000]]);

        Printer::create([
            'node' => 'OLD_NODE',
            'baudRate' => 250000,
            'connected' => false,
            'hasActiveJob' => true,
            'machine' => [
                'uuid' => 'KNOWN-PRINTER/canonical-fingerprint',
                'connectionType' => 'serial',
            ],
        ]);
        $manager = $this->bindFakeSerial(
            new FakeSerialEmulator,
            baudRate: 250000,
            supportedBaudRates: [9600, 115200, 250000]
        );

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $messages = array_column($manager->getLog(), 'message');

        $this->assertSame(self::NODE.' connected at 250000 baud', $messages[0]);
        $this->assertNotContains(self::NODE.' connected at 9600 baud', $messages);
        $this->assertNotContains(self::NODE.' connected at 115200 baud', $messages);
    }

    public function test_it_matches_an_active_print_on_a_new_node_before_scanning_a_transient_old_node(): void
    {
        config(['app.common_baud_rates' => [9600, 115200, 250000]]);

        $activeManager = $this->bindFakeSerial(
            new FakeSerialEmulator,
            baudRate: 250000,
            supportedBaudRates: [9600, 115200, 250000]
        );

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $printer = Printer::firstOrFail();
        $printerId = (string) $printer->_id;
        $machine = $printer->machine;
        $machine['connectionType'] = 'serial';
        $machine['simulated'] = false;
        $printer->node = 'GHOST_NODE';
        $printer->machine = $machine;
        $printer->connected = false;
        $printer->hasActiveJob = true;
        $printer->activeFile = 'partial-burnout.gcode';
        $printer->cameras = ['camera-a'];
        $printer->recordableCameras = ['camera-a'];
        $printer->save();

        Configuration::where('key', 'negotiationWaitSecs')->update(['value' => 7]);

        $ghostManager = new FakeSerialManager(
            cache: new Repository(new ArrayStore),
            emulator: new FakeSerialEmulator,
            settings: [
                'enabled' => true,
                'node' => 'GHOST_NODE',
                'baudRate' => 250000,
                'supportedBaudRates' => [9600, 115200, 250000],
                'logMaxEntries' => 100,
            ]
        );
        $multiNodeManager = new class($activeManager, $ghostManager, self::NODE, 'GHOST_NODE') extends FakeSerialManager
        {
            public function __construct(
                private readonly FakeSerialManager $activeManager,
                private readonly FakeSerialManager $ghostManager,
                private readonly string $activeNode,
                private readonly string $ghostNode
            ) {
                parent::__construct(new Repository(new ArrayStore));
            }

            public function listVirtualNodes(): array
            {
                return [$this->ghostNode, $this->activeNode];
            }

            public function nodeExists(string $node): bool
            {
                return in_array($node, $this->listVirtualNodes(), true);
            }

            public function connect(string $node, int $baudRate): string
            {
                return $this->manager($node)->connect($node, $baudRate);
            }

            public function disconnect(string $node, ?string $token = null): void
            {
                $this->manager($node)->disconnect($node, $token);
            }

            public function transact(
                string $node,
                int $baudRate,
                string $token,
                string $command,
                ?int $timeout = null
            ): array {
                return $this->manager($node)->transact(
                    $node,
                    $baudRate,
                    $token,
                    $command,
                    $timeout
                );
            }

            private function manager(string $node): FakeSerialManager
            {
                return $node === $this->activeNode
                    ? $this->activeManager
                    : $this->ghostManager;
            }
        };
        app()->instance(FakeSerialManager::class, $multiNodeManager);
        $activeLogOffset = count($activeManager->getLog());

        $this->assertSame(0, Artisan::call('map:serial-printers'));

        $printer->refresh();
        $activeMessages = array_column(
            array_slice($activeManager->getLog(), $activeLogOffset),
            'message'
        );

        $this->assertSame($printerId, (string) $printer->_id);
        $this->assertSame(self::NODE, $printer->node);
        $this->assertTrue($printer->connected);
        $this->assertTrue($printer->hasActivePrintJob());
        $this->assertSame(['camera-a'], $printer->cameras);
        $this->assertSame(['camera-a'], $printer->recordableCameras);
        $this->assertContains(self::NODE.' connected at 250000 baud', $activeMessages);
        $this->assertSame([], $ghostManager->getLog());
        $this->assertStringNotContainsString(
            'Waiting 7 seconds for the printer to boot',
            Artisan::output()
        );
    }

    public function test_active_print_fast_path_rejects_a_different_fingerprint(): void
    {
        $this->bindFakeSerial(new FakeSerialEmulator);

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $activePrinter = Printer::firstOrFail();
        $activePrinterId = (string) $activePrinter->_id;
        $machine = $activePrinter->machine;
        $machine['uuid'] = 'OTHER-PRINTER/canonical-fingerprint';
        $machine['connectionType'] = 'serial';
        $machine['simulated'] = false;
        $activePrinter->node = 'OLD_NODE';
        $activePrinter->machine = $machine;
        $activePrinter->connected = false;
        $activePrinter->hasActiveJob = true;
        $activePrinter->activeFile = 'partial-burnout.gcode';
        $activePrinter->save();

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $activePrinter->refresh();
        $mappedPrinter = Printer::where('node', self::NODE)->firstOrFail();

        $this->assertSame($activePrinterId, (string) $activePrinter->_id);
        $this->assertSame('OLD_NODE', $activePrinter->node);
        $this->assertFalse($activePrinter->connected);
        $this->assertNotSame($activePrinterId, (string) $mappedPrinter->_id);
        $this->assertStringStartsWith('FAKESERIAL-DEV-PRINTER/', $mappedPrinter->machine['uuid']);
        $this->assertSame(2, Printer::count());
    }

    public function test_it_falls_back_to_common_rates_when_a_new_node_rejects_the_active_printer_rate(): void
    {
        config(['app.common_baud_rates' => [9600, 115200]]);

        Printer::create([
            'node' => 'OLD_NODE',
            'baudRate' => 250000,
            'connected' => false,
            'hasActiveJob' => true,
            'machine' => [
                'uuid' => 'KNOWN-PRINTER/canonical-fingerprint',
                'connectionType' => 'serial',
            ],
        ]);
        $manager = $this->bindFakeSerial(
            new FakeSerialEmulator,
            baudRate: 115200,
            supportedBaudRates: [9600, 115200, 250000]
        );

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $messages = array_column($manager->getLog(), 'message');
        $knownRateAttempt = array_search(self::NODE.' connected at 250000 baud', $messages, true);
        $firstCommonAttempt = array_search(self::NODE.' connected at 9600 baud', $messages, true);
        $successfulAttempt = array_search(self::NODE.' connected at 115200 baud', $messages, true);

        $this->assertIsInt($knownRateAttempt);
        $this->assertIsInt($firstCommonAttempt);
        $this->assertIsInt($successfulAttempt);
        $this->assertLessThan($firstCommonAttempt, $knownRateAttempt);
        $this->assertLessThan($successfulAttempt, $firstCommonAttempt);
        $this->assertSame(115200, Printer::where('node', self::NODE)->firstOrFail()->baudRate);
    }

    public function test_it_falls_back_to_full_baud_rate_negotiation_when_the_saved_rate_fails(): void
    {
        config(['app.common_baud_rates' => [115200, 250000]]);

        $manager = $this->bindFakeSerial(new FakeSerialEmulator, 115200, [115200, 250000]);

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $printerId = (string) Printer::firstOrFail()->_id;

        $manager->updateSettings(enabled: true, baudRate: 250000);

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $printer = Printer::firstOrFail();

        $this->assertSame($printerId, (string) $printer->_id);
        $this->assertSame(250000, $printer->baudRate);
        $this->assertSame(1, Printer::count());
    }

    public function test_it_regenerates_changed_firmware_information_without_replacing_the_physical_printer(): void
    {
        $emulator = $this->mutableIdentityEmulator();

        $this->bindFakeSerial($emulator);

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $printer = Printer::firstOrFail();
        $printerId = (string) $printer->_id;
        $printer->cameras = ['camera-a'];
        $printer->recordableCameras = ['camera-a'];
        $printer->settings = ['autoScroll' => false];
        $printer->save();

        $emulator->firmwareName = 'Marlin_UPDATED';
        $emulator->arcs = true;

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $printer = Printer::firstOrFail();

        $this->assertSame($printerId, (string) $printer->_id);
        $this->assertSame('Marlin_UPDATED', $printer->machine['firmwareName']);
        $this->assertTrue($printer->machine['capabilities']['arcs']);
        $this->assertSame(['camera-a'], $printer->cameras);
        $this->assertSame(['camera-a'], $printer->recordableCameras);
        $this->assertSame(['autoScroll' => false], $printer->settings);
        $this->assertSame(1, Printer::count());
    }

    public function test_it_creates_a_new_printer_when_the_physical_uuid_changes_at_the_same_node(): void
    {
        $emulator = $this->mutableIdentityEmulator();

        $this->bindFakeSerial($emulator);

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $originalPrinter = Printer::firstOrFail();
        $originalPrinterId = (string) $originalPrinter->_id;

        $emulator->uuid = 'FAKESERIAL-REPLACEMENT';

        $this->assertSame(0, Artisan::call('map:serial-printers', ['node' => self::NODE]));

        $replacement = Printer::where('node', self::NODE)->firstOrFail();
        $originalPrinter->refresh();

        $this->assertNotSame($originalPrinterId, (string) $replacement->_id);
        $this->assertStringStartsWith('FAKESERIAL-REPLACEMENT/', $replacement->machine['uuid']);
        $this->assertNull($originalPrinter->node);
        $this->assertFalse($originalPrinter->connected);
        $this->assertSame(2, Printer::count());
    }

    private function bindFakeSerial(
        FakeSerialEmulator $emulator,
        int $baudRate = 115200,
        array $supportedBaudRates = [115200]
    ): FakeSerialManager {
        $manager = new FakeSerialManager(
            cache: new Repository(new ArrayStore),
            emulator: $emulator,
            settings: [
                'enabled' => true,
                'node' => self::NODE,
                'baudRate' => $baudRate,
                'supportedBaudRates' => $supportedBaudRates,
                'logMaxEntries' => 100,
            ],
        );

        app()->instance(FakeSerialManager::class, $manager);

        return $manager;
    }

    private function mutableIdentityEmulator(): FakeSerialEmulator
    {
        return new class extends FakeSerialEmulator
        {
            public string $firmwareName = 'Marlin FAKE_SERIAL_SIM';

            public string $uuid = 'FAKESERIAL-DEV-PRINTER';

            public bool $arcs = false;

            public function transact(string $command, array $state = []): array
            {
                $result = parent::transact($command, $state);

                if ($command !== 'M115') {
                    return $result;
                }

                foreach ($result['lines'] as &$line) {
                    $line['text'] = str_replace(
                        [
                            'FIRMWARE_NAME:Marlin FAKE_SERIAL_SIM',
                            'UUID:FAKESERIAL-DEV-PRINTER',
                            'Cap:ARCS:0',
                        ],
                        [
                            'FIRMWARE_NAME:'.$this->firmwareName,
                            'UUID:'.$this->uuid,
                            'Cap:ARCS:'.(int) $this->arcs,
                        ],
                        $line['text']
                    );
                }

                unset($line);

                return $result;
            }
        };
    }
}
