<?php

namespace App\Gcode;

final class AdaptiveEta
{
    private float $activeSeconds = 0.0;

    private ?float $lastSampleAt = null;

    private bool $wasRunning = false;

    public function __construct(private readonly int $estimatedSeconds) {}

    public function sample(int $line, int $maxLine, bool $running, ?float $now = null): array
    {
        $now ??= microtime(true);

        if ($this->lastSampleAt !== null && $this->wasRunning) {
            $this->activeSeconds += max(0.0, min(30.0, $now - $this->lastSampleAt));
        }

        $this->lastSampleAt = $now;
        $this->wasRunning = $running;
        $progress = $maxLine > 0 ? max(0.0, min(1.0, $line / $maxLine)) : 0.0;

        if (! $running) {
            return $this->result(null, 'paused', false);
        }

        if ($progress >= 1.0) {
            return $this->result(0, 'completed', true);
        }

        $staticRemaining = max(0.0, $this->estimatedSeconds * (1.0 - $progress));
        $stable = $progress >= 0.10 && $this->activeSeconds >= 30.0;
        $remaining = $staticRemaining;
        $origin = 'estimate';

        if ($stable) {
            $observedTotal = $this->activeSeconds / max($progress, 0.001);
            $observedRemaining = max(0.0, $observedTotal - $this->activeSeconds);
            $weight = min(0.90, 0.75 + (($progress - 0.10) / 2.0));
            $remaining = ($staticRemaining * (1.0 - $weight)) + ($observedRemaining * $weight);
            $origin = 'mixed-analysis';
        }

        // Do not announce a few seconds while meaningful work remains.
        if ($progress < 0.98 && $remaining < 30.0) {
            $remaining = 30.0;
        }

        return $this->result((int) min(PHP_INT_MAX, ceil($remaining)), $origin, $stable);
    }

    public function activeSeconds(): int
    {
        return (int) floor($this->activeSeconds);
    }

    private function result(?int $remaining, string $origin, bool $stable): array
    {
        return [
            'estimatedSeconds' => max(0, $this->estimatedSeconds),
            'printTime' => $this->activeSeconds(),
            'printTimeLeft' => $remaining,
            'printTimeLeftOrigin' => $origin,
            'stable' => $stable,
        ];
    }
}
