<?php

namespace Tests\Unit;

use App\Models\Configuration;
use App\Models\Printer;
use App\Support\FakeSerial\FakeSerialEmulator;
use App\Support\FakeSerial\FakeSerialManager;
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

    private function bindFakeSerial(FakeSerialEmulator $emulator): void
    {
        app()->instance(FakeSerialManager::class, new FakeSerialManager(
            cache: new Repository(new ArrayStore),
            emulator: $emulator,
            settings: [
                'enabled' => true,
                'node' => self::NODE,
                'baudRate' => 115200,
                'supportedBaudRates' => [115200],
                'logMaxEntries' => 100,
            ],
        ));
    }
}
