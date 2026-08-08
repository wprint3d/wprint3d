<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../internal/startup-metrics.php';

class StartupMetricsSamplerTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir().'/wprint3d-startup-metrics-'.bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot.'/proc', 0777, true);
        mkdir($this->fixtureRoot.'/sys/dev/block', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeFixture($this->fixtureRoot);

        parent::tearDown();
    }

    public function test_it_calculates_host_metrics_and_sanitized_process_leaders(): void
    {
        $this->writeHostFixture(
            cpu: 'cpu  100 0 50 800 50 0 0 0',
            memTotalKilobytes: 1000000,
            memAvailableKilobytes: 400000,
            diskLines: [
                '8 0 sda 10 0 100 20 8 0 50 10 0 1000 1000',
                '8 1 sda1 100 0 9000 20 80 0 7000 10 0 9000 9000',
                '253 0 dm-0 100 0 8000 20 80 0 6000 10 0 8000 8000',
                '7 0 loop0 100 0 9999 20 80 0 9999 10 0 9999 9999',
            ],
        );
        $this->createDiskFixture('8:0');
        $this->createDiskFixture('8:1', partition: true);
        $this->createDiskFixture('253:0', hasSlaves: true);
        $this->writeProcessFixture(
            pid: 101,
            comm: 'node',
            command: "node\0/usr/bin/expo\0start\0",
            userTicks: 100,
            systemTicks: 20,
            residentKilobytes: 200000,
            readBytes: 1000,
            writeBytes: 2000,
        );
        $this->writeProcessFixture(
            pid: 102,
            comm: 'php',
            command: "php\0/usr/local/bin/composer\0install\0--no-progress\0",
            userTicks: 50,
            systemTicks: 10,
            residentKilobytes: 100000,
            readBytes: 500,
            writeBytes: 500,
        );
        $this->writeProcessFixture(
            pid: 103,
            comm: 'private worker/unsafe',
            command: "private worker/unsafe\0--token=must-not-leak\0",
            userTicks: 10,
            systemTicks: 5,
            residentKilobytes: 50000,
            readBytes: 100,
            writeBytes: 100,
        );

        $sampler = $this->sampler();
        $before = $sampler->captureSnapshot();

        $this->writeHostFixture(
            cpu: 'cpu  160 0 90 850 60 0 0 0',
            memTotalKilobytes: 1000000,
            memAvailableKilobytes: 250000,
            diskLines: [
                '8 0 sda 20 0 140 30 18 0 90 20 0 1160 1160',
                '8 1 sda1 200 0 19000 20 180 0 17000 10 0 19000 19000',
                '253 0 dm-0 200 0 18000 20 180 0 16000 10 0 18000 18000',
                '7 0 loop0 200 0 19999 20 180 0 19999 10 0 19999 19999',
            ],
        );
        $this->writeProcessFixture(101, 'node', "node\0/usr/bin/expo\0start\0", 140, 30, 230000, 51000, 52000);
        $this->writeProcessFixture(102, 'php', "php\0/usr/local/bin/composer\0install\0--no-progress\0", 65, 15, 120000, 10500, 20500);
        $this->writeProcessFixture(103, 'private worker/unsafe', "private worker/unsafe\0--token=must-not-leak\0", 11, 5, 60000, 100, 100);

        $after = $sampler->captureSnapshot();
        $metrics = $sampler->metricsFromSnapshots($before, $after, 2.0, 7, 1234567890000);

        $this->assertSame(62.5, $metrics['cpu']['usedPercent']);
        $this->assertSame(6.3, $metrics['cpu']['ioWaitPercent']);
        $this->assertSame(75.0, $metrics['memory']['usedPercent']);
        $this->assertSame(10240, $metrics['storage']['readBytesPerSecond']);
        $this->assertSame(10240, $metrics['storage']['writeBytesPerSecond']);
        $this->assertSame(8.0, $metrics['storage']['busyPercent']);
        $this->assertSame('metro', $metrics['leaders']['cpu'][0]['kind']);
        $this->assertSame(31.3, $metrics['leaders']['cpu'][0]['cpuPercent']);
        $this->assertSame('composer', $metrics['leaders']['cpu'][1]['kind']);
        $this->assertSame('unsafe', $metrics['leaders']['memory'][2]['name']);
        $this->assertCount(3, $metrics['leaders']['cpu']);
        $this->assertCount(3, $metrics['leaders']['memory']);
        $this->assertCount(3, $metrics['leaders']['io']);

        $publicPayload = json_encode($metrics, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('pid', $publicPayload);
        $this->assertStringNotContainsString('cmdline', $publicPayload);
        $this->assertStringNotContainsString('token', $publicPayload);
        $this->assertStringNotContainsString('--no-progress', $publicPayload);
        $this->assertStringNotContainsString('private worker', $publicPayload);
    }

    public function test_it_ignores_disappeared_processes_and_clamps_reset_counters(): void
    {
        $sampler = $this->sampler();
        $before = [
            'cpu' => ['total' => 1000, 'idle' => 800, 'ioWait' => 20],
            'memory' => ['usedBytes' => 1, 'totalBytes' => 2, 'usedPercent' => 50.0],
            'disks' => [
                '8:0' => ['readBytes' => 5000, 'writeBytes' => 7000, 'busyMilliseconds' => 900],
            ],
            'processes' => [
                10 => [
                    'identity' => ['kind' => 'composer'],
                    'cpuTicks' => 100,
                    'residentBytes' => 1000,
                    'readBytes' => 500,
                    'writeBytes' => 500,
                ],
                11 => [
                    'identity' => ['kind' => 'mongodb'],
                    'cpuTicks' => 100,
                    'residentBytes' => 2000,
                    'readBytes' => 500,
                    'writeBytes' => 500,
                ],
            ],
        ];
        $after = [
            'cpu' => ['total' => 1100, 'idle' => 850, 'ioWait' => 25],
            'memory' => ['usedBytes' => 1, 'totalBytes' => 2, 'usedPercent' => 50.0],
            'disks' => [
                '8:0' => ['readBytes' => 100, 'writeBytes' => 200, 'busyMilliseconds' => 10],
            ],
            'processes' => [
                10 => [
                    'identity' => ['kind' => 'composer'],
                    'cpuTicks' => 10,
                    'residentBytes' => 1200,
                    'readBytes' => 50,
                    'writeBytes' => 50,
                ],
                12 => [
                    'identity' => ['kind' => 'laravel'],
                    'cpuTicks' => 20,
                    'residentBytes' => 3000,
                    'readBytes' => 100,
                    'writeBytes' => 100,
                ],
            ],
        ];

        $metrics = $sampler->metricsFromSnapshots($before, $after, 2.0, 1);

        $this->assertSame(0, $metrics['storage']['readBytesPerSecond']);
        $this->assertSame(0, $metrics['storage']['writeBytesPerSecond']);
        $this->assertSame(0.0, $metrics['leaders']['cpu'][0]['cpuPercent']);
        $this->assertSame('composer', $metrics['leaders']['cpu'][0]['kind']);
        $this->assertSame('laravel', $metrics['leaders']['memory'][0]['kind']);
        $this->assertNotContains('mongodb', array_column($metrics['leaders']['memory'], 'kind'));
    }

    public function test_it_tolerates_unreadable_optional_process_files(): void
    {
        $this->writeHostFixture(
            cpu: 'cpu  100 0 50 800 50 0 0 0',
            memTotalKilobytes: 1000,
            memAvailableKilobytes: 500,
            diskLines: [],
        );
        $processDirectory = $this->fixtureRoot.'/proc/200';
        mkdir($processDirectory);
        file_put_contents($processDirectory.'/stat', $this->processStat(200, 'worker', 10, 5));
        mkdir($processDirectory.'/cmdline');
        mkdir($processDirectory.'/status');
        mkdir($processDirectory.'/io');

        $deniedProcessDirectory = $this->fixtureRoot.'/proc/201';
        mkdir($deniedProcessDirectory.'/stat', 0777, true);

        $snapshot = $this->sampler()->captureSnapshot();

        $this->assertArrayNotHasKey(201, $snapshot['processes']);
        $this->assertSame(0, $snapshot['processes'][200]['residentBytes']);
        $this->assertSame(0, $snapshot['processes'][200]['readBytes']);
        $this->assertSame(0, $snapshot['processes'][200]['writeBytes']);
        $this->assertSame('worker', $snapshot['processes'][200]['identity']['name']);
    }

    public function test_it_classifies_known_startup_processes(): void
    {
        $this->writeHostFixture(
            cpu: 'cpu  100 0 50 800 50 0 0 0',
            memTotalKilobytes: 1000,
            memAvailableKilobytes: 500,
            diskLines: [],
        );

        $fixtures = [
            301 => ['node', "node\0metro\0"],
            302 => ['php', "php\0composer\0install\0"],
            303 => ['php', "php\0artisan\0queue:work\0"],
            304 => ['mongod', "mongod\0"],
            305 => ['redis-server', "redis-server\0"],
            306 => ['nginx', "nginx\0"],
            307 => ['containerd', "containerd\0"],
        ];

        foreach ($fixtures as $pid => [$comm, $command]) {
            $this->writeProcessFixture($pid, $comm, $command, 1, 1, 1, 0, 0);
        }

        $identities = array_column($this->sampler()->captureSnapshot()['processes'], 'identity');

        $this->assertSame(
            ['metro', 'composer', 'laravel', 'mongodb', 'redis', 'proxy', 'docker'],
            array_column($identities, 'kind'),
        );
    }

    public function test_it_excludes_the_sampler_from_process_leaders(): void
    {
        $this->writeHostFixture(
            cpu: 'cpu  100 0 50 800 50 0 0 0',
            memTotalKilobytes: 1000,
            memAvailableKilobytes: 500,
            diskLines: [],
        );
        $this->writeProcessFixture(
            getmypid(),
            'php',
            "php\0internal/startup-metrics.php\0",
            100,
            100,
            100,
            100,
            100,
        );
        $hostProcessId = 999999;
        $this->writeProcessFixture(
            $hostProcessId,
            'php',
            "php\0internal/startup-metrics.php\0",
            100,
            100,
            100,
            100,
            100,
        );
        symlink((string) $hostProcessId, $this->fixtureRoot.'/proc/self');

        $processes = $this->sampler()->captureSnapshot()['processes'];

        $this->assertArrayNotHasKey(getmypid(), $processes);
        $this->assertArrayNotHasKey($hostProcessId, $processes);
    }

    public function test_it_publishes_metrics_atomically(): void
    {
        $outputPath = $this->fixtureRoot.'/output/host-metrics.txt';
        $metrics = ['version' => 1, 'sequence' => 9];

        $this->sampler()->writeMetricsAtomically($outputPath, $metrics);

        $this->assertSame($metrics, json_decode(file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame([], glob(dirname($outputPath).'/*.tmp.txt'));
    }

    private function sampler(): StartupMetricsSampler
    {
        return new StartupMetricsSampler($this->fixtureRoot.'/proc', $this->fixtureRoot.'/sys');
    }

    private function writeHostFixture(
        string $cpu,
        int $memTotalKilobytes,
        int $memAvailableKilobytes,
        array $diskLines,
    ): void {
        file_put_contents($this->fixtureRoot.'/proc/stat', $cpu.PHP_EOL);
        file_put_contents(
            $this->fixtureRoot.'/proc/meminfo',
            "MemTotal:       {$memTotalKilobytes} kB".PHP_EOL
            ."MemAvailable:   {$memAvailableKilobytes} kB".PHP_EOL,
        );
        file_put_contents($this->fixtureRoot.'/proc/diskstats', implode(PHP_EOL, $diskLines).PHP_EOL);
    }

    private function createDiskFixture(string $device, bool $partition = false, bool $hasSlaves = false): void
    {
        $path = $this->fixtureRoot.'/sys/dev/block/'.$device;
        mkdir($path.'/slaves', 0777, true);

        if ($partition) {
            file_put_contents($path.'/partition', '1');
        }

        if ($hasSlaves) {
            mkdir($path.'/slaves/sda');
        }
    }

    private function writeProcessFixture(
        int $pid,
        string $comm,
        string $command,
        int $userTicks,
        int $systemTicks,
        int $residentKilobytes,
        int $readBytes,
        int $writeBytes,
    ): void {
        $path = $this->fixtureRoot.'/proc/'.$pid;

        if (! is_dir($path)) {
            mkdir($path);
        }

        file_put_contents($path.'/stat', $this->processStat($pid, $comm, $userTicks, $systemTicks));
        file_put_contents($path.'/cmdline', $command);
        file_put_contents($path.'/status', "Name:\t{$comm}".PHP_EOL."VmRSS:\t{$residentKilobytes} kB".PHP_EOL);
        file_put_contents($path.'/io', "read_bytes: {$readBytes}".PHP_EOL."write_bytes: {$writeBytes}".PHP_EOL);
    }

    private function processStat(int $pid, string $comm, int $userTicks, int $systemTicks): string
    {
        return "{$pid} ({$comm}) S 1 1 1 0 -1 0 0 0 0 0 {$userTicks} {$systemTicks} 0 0 20 0 1 0 100 0 0".PHP_EOL;
    }

    private function removeFixture(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }

        if (! is_dir($path) || is_link($path)) {
            unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeFixture($path.'/'.$entry);
        }

        rmdir($path);
    }
}
