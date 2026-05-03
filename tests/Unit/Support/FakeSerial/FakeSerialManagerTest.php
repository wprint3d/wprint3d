<?php

namespace Tests\Unit\Support\FakeSerial;

use App\Exceptions\InitializationException;
use App\Exceptions\TimedOutException;
use App\Support\FakeSerial\FakeSerialManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

class FakeSerialManagerTest extends TestCase
{
    private function makeManager(array $settings = []): FakeSerialManager
    {
        return new FakeSerialManager(
            cache: new Repository(new ArrayStore),
            settings: array_merge([
                'enabled' => false,
                'node' => 'FAKE0',
                'baudRate' => 115200,
                'supportedBaudRates' => [115200, 250000],
                'logMaxEntries' => 100,
            ], $settings)
        );
    }

    public function test_it_hides_the_virtual_node_when_disabled(): void
    {
        $manager = $this->makeManager([
            'enabled' => false,
        ]);

        $this->assertSame([], $manager->listVirtualNodes());
        $this->assertFalse($manager->nodeExists('FAKE0'));
    }

    public function test_it_exposes_the_virtual_node_when_enabled(): void
    {
        $manager = $this->makeManager([
            'enabled' => true,
        ]);

        $this->assertSame(['FAKE0'], $manager->listVirtualNodes());
        $this->assertTrue($manager->nodeExists('FAKE0'));
    }

    public function test_only_the_configured_baud_rate_succeeds(): void
    {
        $manager = $this->makeManager([
            'enabled' => true,
            'baudRate' => 250000,
        ]);

        $goodConnection = $manager->connect('FAKE0', 250000);
        $goodResult = $manager->transact('FAKE0', 250000, $goodConnection, 'M105', 1);
        $manager->disconnect('FAKE0', $goodConnection);

        $this->assertStringContainsString('ok T:', $goodResult['response']);

        $badConnection = $manager->connect('FAKE0', 115200);

        $this->expectException(TimedOutException::class);

        try {
            $manager->transact('FAKE0', 115200, $badConnection, 'M105', 1);
        } finally {
            $manager->disconnect('FAKE0', $badConnection);
        }
    }

    public function test_it_rejects_a_second_simultaneous_connection(): void
    {
        $manager = $this->makeManager([
            'enabled' => true,
        ]);

        $connection = $manager->connect('FAKE0', 115200);

        $this->expectException(InitializationException::class);

        try {
            $manager->connect('FAKE0', 115200);
        } finally {
            $manager->disconnect('FAKE0', $connection);
        }
    }

    public function test_it_caps_the_developer_log_when_batching_transaction_entries(): void
    {
        $manager = $this->makeManager([
            'enabled' => true,
            'logMaxEntries' => 5,
        ]);

        $connection = $manager->connect('FAKE0', 115200);

        try {
            $manager->transact('FAKE0', 115200, $connection, 'G1 X1 Y1 Z0.2 E0.1');
            $manager->transact('FAKE0', 115200, $connection, 'G1 X2 Y2 Z0.2 E0.2');
            $manager->transact('FAKE0', 115200, $connection, 'G1 X3 Y3 Z0.2 E0.3');

            $log = $manager->getLog();

            $this->assertCount(5, $log);
            $this->assertSame('output', $log[4]['direction']);
            $this->assertSame('ok', $log[4]['message']);
        } finally {
            $manager->disconnect('FAKE0', $connection);
        }
    }
}
