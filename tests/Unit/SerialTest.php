<?php

namespace Tests\Unit;

use App\Exceptions\InitializationException;
use App\Exceptions\TimedOutException;
use App\Libraries\Serial;
use App\Models\Configuration;
use App\Support\FakeSerial\FakeSerialEmulator;
use App\Support\FakeSerial\FakeSerialManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock as BaseLock;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use Tests\TestCase;

class SerialTest extends TestCase
{
    private const NODE = 'SERIAL_TEST';

    private array $hooks;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Configuration::query()->delete();

        Configuration::create(['key' => 'debugSerial', 'value' => false]);
        Configuration::create(['key' => 'terminalMaxLines', 'value' => 100]);
        Configuration::create(['key' => 'commandTimeoutSecs', 'value' => 1]);
        Configuration::create(['key' => 'lastSeenThresholdSecs', 'value' => 7]);

        $this->hooks = [
            'serial.command.before_send' => static fn (array $context = []): array => [],
            'serial.line.received' => static fn (array $context = []): array => [],
            'serial.command.response_received' => static fn (array $context = []): array => [],
        ];
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(FakeSerialManager::class);
        Cache::flush();
        Configuration::query()->delete();

        parent::tearDown();
    }

    public function test_constructor_timeout_is_absolute_and_the_next_query_reconnects(): void
    {
        $emulator = new class extends FakeSerialEmulator
        {
            public function transact(string $command, array $state = []): array
            {
                if ($command === 'AUDIT_SLOW') {
                    return [
                        'state' => $state,
                        'response' => 'ok delayed',
                        'lines' => [['text' => 'ok delayed', 'delayMs' => 2500]],
                    ];
                }

                return parent::transact($command, $state);
            }
        };

        $this->bindFakeSerial($emulator);
        $serial = $this->makeSerial(timeout: 1);
        $startedAt = hrtime(true);

        try {
            $serial->query('AUDIT_SLOW');
            $this->fail('The delayed response should time out.');
        } catch (TimedOutException) {
            $elapsedSecs = (hrtime(true) - $startedAt) / 1_000_000_000;

            $this->assertGreaterThanOrEqual(0.9, $elapsedSecs);
            $this->assertLessThan(1.8, $elapsedSecs);
        }

        $this->assertStringContainsString('ok T:', $serial->query('M105'));
        $serial->close();
    }

    public function test_it_rejects_invalid_utf8_and_oversized_responses(): void
    {
        $emulator = new class extends FakeSerialEmulator
        {
            public function transact(string $command, array $state = []): array
            {
                if ($command === 'AUDIT_CORRUPT') {
                    return [
                        'state' => $state,
                        'response' => "\xff\xfe\nok",
                        'lines' => [
                            ['text' => "\xff\xfe", 'delayMs' => 0],
                            ['text' => 'ok', 'delayMs' => 0],
                        ],
                    ];
                }

                if ($command === 'AUDIT_OVERSIZED') {
                    $response = str_repeat('X', Serial::MAX_RESPONSE_BYTES + 1);

                    return [
                        'state' => $state,
                        'response' => $response,
                        'lines' => [['text' => $response, 'delayMs' => 0]],
                    ];
                }

                return parent::transact($command, $state);
            }
        };

        $this->bindFakeSerial($emulator);
        $serial = $this->makeSerial();

        foreach (['AUDIT_CORRUPT', 'AUDIT_OVERSIZED'] as $command) {
            try {
                $serial->query($command);
                $this->fail("{$command} should be rejected.");
            } catch (InitializationException) {
                $this->assertStringContainsString('ok T:', $serial->query('M105'));
            }
        }

        $serial->close();
    }

    public function test_contended_lock_is_still_owned_when_returned(): void
    {
        $scriptedLock = new SerialScriptedLock;
        $serialReflection = new ReflectionClass(Serial::class);
        $serial = $serialReflection->newInstanceWithoutConstructor();

        $cacheProperty = $serialReflection->getProperty('lockCache');
        $cacheProperty->setValue($serial, new SerialLockRepository($scriptedLock));

        $keyProperty = $serialReflection->getProperty('lockKey');
        $keyProperty->setValue($serial, 'serial-test-lock');

        $method = $serialReflection->getMethod('blockWhileLocking');
        $returnedLock = $method->invoke($serial, 10);

        $this->assertSame($scriptedLock, $returnedLock);
        $this->assertTrue($scriptedLock->owned);
        $this->assertSame(1, $scriptedLock->blockCalls);
        $this->assertSame(0, $scriptedLock->releaseCount);
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

    private function makeSerial(int $timeout = 1): Serial
    {
        return new Serial(
            fileName: self::NODE,
            baudRate: 115200,
            timeout: $timeout,
            pluginHooks: $this->hooks,
        );
    }
}

class SerialScriptedLock extends BaseLock
{
    public bool $owned = false;

    public int $blockCalls = 0;

    public int $releaseCount = 0;

    public function __construct()
    {
        parent::__construct('serial-test-lock', 10);
    }

    public function acquire(): bool
    {
        return false;
    }

    public function block($seconds, $callback = null): bool
    {
        $this->blockCalls++;
        $this->owned = true;

        return true;
    }

    public function release(): bool
    {
        $this->releaseCount++;
        $this->owned = false;

        return true;
    }

    public function forceRelease(): void
    {
        $this->owned = false;
    }

    protected function getCurrentOwner(): ?string
    {
        return $this->owned ? $this->owner() : null;
    }
}

class SerialLockRepository extends Repository
{
    public function __construct(private SerialScriptedLock $scriptedLock)
    {
        parent::__construct(new ArrayStore);
    }

    public function lock($name, $seconds = 0, $owner = null): SerialScriptedLock
    {
        return $this->scriptedLock;
    }
}
