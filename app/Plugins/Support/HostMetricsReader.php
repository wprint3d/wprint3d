<?php

namespace App\Plugins\Support;

use RuntimeException;

class HostMetricsReader
{
    public function fromHost(int $sampleDelayMicros = 200000): array
    {
        $statBefore = $this->readFile('/proc/stat');
        usleep($sampleDelayMicros);
        $statAfter = $this->readFile('/proc/stat');
        $memInfo = $this->readFile('/proc/meminfo');

        return $this->fromSnapshots($statBefore, $statAfter, $memInfo);
    }

    public function fromSnapshots(string $statBefore, string $statAfter, string $memInfo): array
    {
        [$beforeTotal, $beforeIdle] = $this->parseCpuLine($statBefore);
        [$afterTotal, $afterIdle] = $this->parseCpuLine($statAfter);

        $deltaTotal = max(0, $afterTotal - $beforeTotal);
        $deltaIdle = max(0, $afterIdle - $beforeIdle);
        $cpuUsedPercentage = $deltaTotal > 0
            ? (($deltaTotal - $deltaIdle) / $deltaTotal) * 100
            : 0.0;

        $memory = $this->parseMemoryInfo($memInfo);

        return [
            'cpu' => [
                'usedPercentage' => round($cpuUsedPercentage, 1),
            ],
            'ram' => [
                'totalKilobytes' => $memory['total'],
                'availableKilobytes' => $memory['available'],
                'usedKilobytes' => $memory['used'],
                'usedPercentage' => round($memory['usedPercentage'], 1),
            ],
        ];
    }

    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read host metrics file: {$path}");
        }

        return $contents;
    }

    private function parseCpuLine(string $statContents): array
    {
        $line = collect(preg_split('/\r\n|\r|\n/', trim($statContents)) ?: [])
            ->first(fn ($candidate) => str_starts_with(trim($candidate), 'cpu '));

        if (! $line) {
            throw new RuntimeException('Unable to parse CPU stats from /proc/stat.');
        }

        $values = array_values(array_filter(
            preg_split('/\s+/', trim($line)) ?: [],
            fn ($value) => $value !== ''
        ));

        array_shift($values);
        $ticks = array_map('intval', $values);
        $total = array_sum($ticks);
        $idle = ($ticks[3] ?? 0) + ($ticks[4] ?? 0);

        return [$total, $idle];
    }

    private function parseMemoryInfo(string $memInfo): array
    {
        preg_match('/^MemTotal:\s+(\d+)\s+kB$/mi', $memInfo, $totalMatch);
        preg_match('/^MemAvailable:\s+(\d+)\s+kB$/mi', $memInfo, $availableMatch);

        $total = isset($totalMatch[1]) ? (int) $totalMatch[1] : 0;
        $available = isset($availableMatch[1]) ? (int) $availableMatch[1] : 0;

        if ($total <= 0) {
            throw new RuntimeException('Unable to parse memory totals from /proc/meminfo.');
        }

        $used = max(0, $total - $available);

        return [
            'total' => $total,
            'available' => $available,
            'used' => $used,
            'usedPercentage' => ($used / $total) * 100,
        ];
    }
}
