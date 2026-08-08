<?php

final class StartupMetricsSampler
{
    private const DISK_SECTOR_BYTES = 512;

    private const TOP_PROCESS_LIMIT = 3;

    public function __construct(
        private readonly string $procRoot = '/proc',
        private readonly string $sysRoot = '/sys',
    ) {}

    public function captureSnapshot(): array
    {
        return [
            'cpu' => $this->readCpuSnapshot(),
            'disks' => $this->readDiskSnapshot(),
            'memory' => $this->readMemorySnapshot(),
            'processes' => $this->readProcessSnapshots(),
        ];
    }

    public function metricsFromSnapshots(
        array $before,
        array $after,
        float $elapsedSeconds,
        int $sequence,
        ?int $sampledAtMilliseconds = null,
    ): array {
        $elapsedSeconds = max(0.001, $elapsedSeconds);
        $cpu = $this->calculateCpuMetrics($before['cpu'], $after['cpu']);
        $storage = $this->calculateStorageMetrics(
            $before['disks'],
            $after['disks'],
            $elapsedSeconds,
        );
        $processes = $this->calculateProcessMetrics(
            $before['processes'],
            $after['processes'],
            $before['cpu'],
            $after['cpu'],
            $elapsedSeconds,
        );

        return [
            'version' => 1,
            'sequence' => $sequence,
            'sampledAt' => $sampledAtMilliseconds ?? (int) round(microtime(true) * 1000),
            'intervalMs' => (int) round($elapsedSeconds * 1000),
            'cpu' => $cpu,
            'memory' => $after['memory'],
            'storage' => $storage,
            'leaders' => [
                'cpu' => $this->topProcesses($processes, 'cpuPercent'),
                'memory' => $this->topProcesses($processes, 'residentBytes'),
                'io' => $this->topProcesses($processes, 'ioBytesPerSecond'),
            ],
        ];
    }

    public function writeMetricsAtomically(string $outputPath, array $metrics): void
    {
        $directory = dirname($outputPath);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create startup metrics directory: {$directory}");
        }

        $temporaryPath = $directory.'/host-metrics-'.getmypid().'.tmp.txt';
        $payload = json_encode(
            $metrics,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ).PHP_EOL;

        if (file_put_contents($temporaryPath, $payload, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write temporary startup metrics file: {$temporaryPath}");
        }

        @chmod($temporaryPath, 0644);

        if (! rename($temporaryPath, $outputPath)) {
            @unlink($temporaryPath);

            throw new RuntimeException("Unable to publish startup metrics file: {$outputPath}");
        }
    }

    public function run(string $outputPath, int $intervalMilliseconds = 2000): void
    {
        $intervalMilliseconds = max(250, $intervalMilliseconds);
        $running = true;

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$running): void {
                $running = false;
            });
            pcntl_signal(SIGINT, static function () use (&$running): void {
                $running = false;
            });
        }

        $sequence = 0;
        $before = $this->captureSnapshot();
        $capturedAt = hrtime(true);

        while ($running) {
            usleep($intervalMilliseconds * 1000);

            if (! $running) {
                break;
            }

            try {
                $after = $this->captureSnapshot();
                $nextCapturedAt = hrtime(true);
                $elapsedSeconds = max(0.001, ($nextCapturedAt - $capturedAt) / 1_000_000_000);
                $sequence++;

                $this->writeMetricsAtomically(
                    $outputPath,
                    $this->metricsFromSnapshots($before, $after, $elapsedSeconds, $sequence),
                );

                $before = $after;
                $capturedAt = $nextCapturedAt;
            } catch (Throwable $error) {
                fwrite(STDERR, 'Unable to refresh startup host metrics: '.$error->getMessage().PHP_EOL);
            }
        }
    }

    private function readCpuSnapshot(): array
    {
        $contents = $this->readRequiredFile($this->procRoot.'/stat');
        $line = null;

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $candidate) {
            if (str_starts_with($candidate, 'cpu ')) {
                $line = $candidate;
                break;
            }
        }

        if ($line === null) {
            throw new RuntimeException('Unable to parse aggregate CPU metrics.');
        }

        $values = preg_split('/\s+/', trim($line)) ?: [];
        array_shift($values);
        $ticks = array_map('intval', array_pad($values, 8, 0));
        $total = array_sum(array_slice($ticks, 0, 8));

        return [
            'total' => $total,
            'idle' => ($ticks[3] ?? 0) + ($ticks[4] ?? 0),
            'ioWait' => $ticks[4] ?? 0,
        ];
    }

    private function readMemorySnapshot(): array
    {
        $contents = $this->readRequiredFile($this->procRoot.'/meminfo');
        preg_match('/^MemTotal:\s+(\d+)\s+kB$/mi', $contents, $totalMatch);
        preg_match('/^MemAvailable:\s+(\d+)\s+kB$/mi', $contents, $availableMatch);

        $totalKilobytes = isset($totalMatch[1]) ? (int) $totalMatch[1] : 0;
        $availableKilobytes = isset($availableMatch[1]) ? (int) $availableMatch[1] : 0;

        if ($totalKilobytes <= 0) {
            throw new RuntimeException('Unable to parse host memory metrics.');
        }

        $usedKilobytes = max(0, $totalKilobytes - $availableKilobytes);

        return [
            'usedBytes' => $usedKilobytes * 1024,
            'totalBytes' => $totalKilobytes * 1024,
            'usedPercent' => $this->roundPercent(($usedKilobytes / $totalKilobytes) * 100),
        ];
    }

    private function readDiskSnapshot(): array
    {
        $contents = $this->readRequiredFile($this->procRoot.'/diskstats');
        $disks = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($contents)) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (count($fields) < 14) {
                continue;
            }

            [$major, $minor, $name] = $fields;

            if (! $this->shouldIncludeDisk((int) $major, (int) $minor, $name)) {
                continue;
            }

            $disks[$major.':'.$minor] = [
                'readBytes' => ((int) $fields[5]) * self::DISK_SECTOR_BYTES,
                'writeBytes' => ((int) $fields[9]) * self::DISK_SECTOR_BYTES,
                'busyMilliseconds' => (int) $fields[12],
            ];
        }

        return $disks;
    }

    private function shouldIncludeDisk(int $major, int $minor, string $name): bool
    {
        if (preg_match('/^(loop|ram|zram|fd|sr)/', $name)) {
            return false;
        }

        $devicePath = $this->sysRoot.'/dev/block/'.$major.':'.$minor;

        if (! file_exists($devicePath)) {
            return false;
        }

        if (file_exists($devicePath.'/partition')) {
            return false;
        }

        $slaves = glob($devicePath.'/slaves/*');

        return $slaves === false || $slaves === [];
    }

    private function readProcessSnapshots(): array
    {
        $processes = [];
        $samplerProcessIds = [getmypid()];
        $resolvedSelfPath = realpath($this->procRoot.'/self');

        if ($resolvedSelfPath !== false && ctype_digit(basename($resolvedSelfPath))) {
            $samplerProcessIds[] = (int) basename($resolvedSelfPath);
        }

        foreach (glob($this->procRoot.'/[0-9]*', GLOB_ONLYDIR) ?: [] as $processPath) {
            if (in_array((int) basename($processPath), $samplerProcessIds, true)) {
                continue;
            }

            $process = $this->readProcessSnapshot($processPath);

            if ($process === null) {
                continue;
            }

            $processes[basename($processPath)] = $process;
        }

        return $processes;
    }

    private function readProcessSnapshot(string $processPath): ?array
    {
        $statPath = $processPath.'/stat';

        if (! is_file($statPath) || ! is_readable($statPath)) {
            return null;
        }

        $stat = @file_get_contents($statPath);

        if ($stat === false) {
            return null;
        }

        $openingParenthesis = strpos($stat, '(');
        $closingParenthesis = strrpos($stat, ')');

        if ($openingParenthesis === false || $closingParenthesis === false || $closingParenthesis <= $openingParenthesis) {
            return null;
        }

        $comm = substr($stat, $openingParenthesis + 1, $closingParenthesis - $openingParenthesis - 1);
        $fields = preg_split('/\s+/', trim(substr($stat, $closingParenthesis + 1))) ?: [];

        if (count($fields) < 13) {
            return null;
        }

        $cmdline = $this->readOptionalFile($processPath.'/cmdline');
        $identity = $this->classifyProcess($comm, $cmdline);
        $status = $this->readOptionalFile($processPath.'/status');
        $io = $this->readOptionalFile($processPath.'/io');

        return [
            'identity' => $identity,
            'cpuTicks' => (int) ($fields[11] ?? 0) + (int) ($fields[12] ?? 0),
            'residentBytes' => $this->readResidentBytes($status),
            'readBytes' => $this->readProcessIoCounter($io, 'read_bytes'),
            'writeBytes' => $this->readProcessIoCounter($io, 'write_bytes'),
        ];
    }

    private function classifyProcess(string $comm, string $cmdline): array
    {
        $normalizedCommand = strtolower(str_replace("\0", ' ', $cmdline));
        $normalizedComm = strtolower($comm);

        if (str_contains($normalizedCommand, 'metro') || str_contains($normalizedCommand, 'expo start')) {
            return ['kind' => 'metro'];
        }

        if (str_contains($normalizedCommand, 'composer') || $normalizedComm === 'composer') {
            return ['kind' => 'composer'];
        }

        if (
            str_contains($normalizedCommand, 'artisan')
            || str_contains($normalizedCommand, 'octane')
            || str_starts_with($normalizedComm, 'php-fpm')
        ) {
            return ['kind' => 'laravel'];
        }

        if ($normalizedComm === 'mongod') {
            return ['kind' => 'mongodb'];
        }

        if (str_starts_with($normalizedComm, 'redis-server')) {
            return ['kind' => 'redis'];
        }

        if ($normalizedComm === 'nginx') {
            return ['kind' => 'proxy'];
        }

        if (in_array($normalizedComm, ['docker', 'dockerd', 'containerd'], true)) {
            return ['kind' => 'docker'];
        }

        return [
            'kind' => 'other',
            'name' => $this->sanitizeProcessName($comm),
        ];
    }

    private function sanitizeProcessName(string $name): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._+-]/', '_', basename(trim($name))) ?? '';
        $sanitized = trim($sanitized, '._-');

        return substr($sanitized !== '' ? $sanitized : 'process', 0, 32);
    }

    private function readResidentBytes(string $status): int
    {
        if (preg_match('/^VmRSS:\s+(\d+)\s+kB$/mi', $status, $match) !== 1) {
            return 0;
        }

        return ((int) $match[1]) * 1024;
    }

    private function readProcessIoCounter(string $io, string $counter): int
    {
        if (preg_match('/^'.preg_quote($counter, '/').':\s+(\d+)$/mi', $io, $match) !== 1) {
            return 0;
        }

        return (int) $match[1];
    }

    private function calculateCpuMetrics(array $before, array $after): array
    {
        $totalDelta = max(0, $after['total'] - $before['total']);
        $idleDelta = max(0, $after['idle'] - $before['idle']);
        $ioWaitDelta = max(0, $after['ioWait'] - $before['ioWait']);

        return [
            'usedPercent' => $totalDelta > 0
                ? $this->roundPercent((max(0, $totalDelta - $idleDelta) / $totalDelta) * 100)
                : 0.0,
            'ioWaitPercent' => $totalDelta > 0
                ? $this->roundPercent(($ioWaitDelta / $totalDelta) * 100)
                : 0.0,
        ];
    }

    private function calculateStorageMetrics(array $before, array $after, float $elapsedSeconds): array
    {
        $readBytes = 0;
        $writeBytes = 0;
        $highestBusyPercent = 0.0;

        foreach ($after as $device => $counters) {
            if (! isset($before[$device])) {
                continue;
            }

            $readBytes += max(0, $counters['readBytes'] - $before[$device]['readBytes']);
            $writeBytes += max(0, $counters['writeBytes'] - $before[$device]['writeBytes']);
            $busyDelta = max(0, $counters['busyMilliseconds'] - $before[$device]['busyMilliseconds']);
            $busyPercent = min(100, ($busyDelta / ($elapsedSeconds * 1000)) * 100);
            $highestBusyPercent = max($highestBusyPercent, $busyPercent);
        }

        return [
            'readBytesPerSecond' => (int) round($readBytes / $elapsedSeconds),
            'writeBytesPerSecond' => (int) round($writeBytes / $elapsedSeconds),
            'busyPercent' => $this->roundPercent($highestBusyPercent),
        ];
    }

    private function calculateProcessMetrics(
        array $before,
        array $after,
        array $cpuBefore,
        array $cpuAfter,
        float $elapsedSeconds,
    ): array {
        $totalCpuDelta = max(1, $cpuAfter['total'] - $cpuBefore['total']);
        $aggregated = [];

        foreach ($after as $pid => $process) {
            $identity = $process['identity'];
            $identityKey = $identity['kind'].'|'.($identity['name'] ?? '');
            $previous = $before[$pid] ?? null;
            $cpuDelta = $previous === null
                ? 0
                : max(0, $process['cpuTicks'] - $previous['cpuTicks']);
            $readDelta = $previous === null
                ? 0
                : max(0, $process['readBytes'] - $previous['readBytes']);
            $writeDelta = $previous === null
                ? 0
                : max(0, $process['writeBytes'] - $previous['writeBytes']);

            $aggregated[$identityKey] ??= [
                ...$identity,
                'cpuPercent' => 0.0,
                'residentBytes' => 0,
                'ioBytesPerSecond' => 0,
            ];

            $aggregated[$identityKey]['cpuPercent'] += ($cpuDelta / $totalCpuDelta) * 100;
            $aggregated[$identityKey]['residentBytes'] += max(0, $process['residentBytes']);
            $aggregated[$identityKey]['ioBytesPerSecond'] += (int) round(
                ($readDelta + $writeDelta) / $elapsedSeconds,
            );
        }

        return array_map(static function (array $process): array {
            $process['cpuPercent'] = round(min(100, max(0, $process['cpuPercent'])), 1);

            return $process;
        }, array_values($aggregated));
    }

    private function topProcesses(array $processes, string $metric): array
    {
        usort($processes, static function (array $left, array $right) use ($metric): int {
            $comparison = ($right[$metric] ?? 0) <=> ($left[$metric] ?? 0);

            if ($comparison !== 0) {
                return $comparison;
            }

            return ($left['kind'] ?? '') <=> ($right['kind'] ?? '');
        });

        return array_slice($processes, 0, self::TOP_PROCESS_LIMIT);
    }

    private function roundPercent(float $value): float
    {
        return round(min(100, max(0, $value)), 1);
    }

    private function readRequiredFile(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read host metrics file: {$path}");
        }

        return $contents;
    }

    private function readOptionalFile(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $contents = @file_get_contents($path);

        return $contents === false ? '' : $contents;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = getopt('', ['output:', 'interval-ms::', 'proc-root::', 'sys-root::']);
    $outputPath = $options['output'] ?? null;

    if (! is_string($outputPath) || trim($outputPath) === '') {
        fwrite(STDERR, 'Usage: php internal/startup-metrics.php --output=<path> [--interval-ms=2000]'.PHP_EOL);
        exit(2);
    }

    $sampler = new StartupMetricsSampler(
        is_string($options['proc-root'] ?? null) ? $options['proc-root'] : '/proc',
        is_string($options['sys-root'] ?? null) ? $options['sys-root'] : '/sys',
    );
    $sampler->run(
        $outputPath,
        max(250, (int) ($options['interval-ms'] ?? 2000)),
    );
}
