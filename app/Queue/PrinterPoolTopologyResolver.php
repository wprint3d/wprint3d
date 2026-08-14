<?php

namespace App\Queue;

use App\Models\Printer;
use FacuM\EfficientQueues\Contracts\PoolTopologyResolver;
use InvalidArgumentException;

class PrinterPoolTopologyResolver implements PoolTopologyResolver
{
    public function resolve(): array
    {
        $activePrints = Printer::where('hasActiveJob', true)
            ->where('activeFile', '!=', null)
            ->count();

        return self::topologyFor(
            (string) config('queue.worker_pools', ''),
            (int) $activePrints,
            (string) config('queue.default', 'redis'),
        );
    }

    public static function topologyFor(string $definition, int $activePrints, string $connection = 'redis'): array
    {
        if ($activePrints < 0) {
            throw new InvalidArgumentException('The active print count cannot be negative.');
        }

        $entries = array_values(array_filter(array_map('trim', explode(',', $definition)), fn (string $entry) => $entry !== ''));
        if ($entries === []) {
            throw new InvalidArgumentException('At least one queue pool must be configured.');
        }

        $pools = [];
        $names = [];

        foreach ($entries as $entry) {
            $fields = array_map('trim', explode(':', $entry, 2));
            $name = $fields[0];
            $minimum = $fields[1] ?? null;

            if ($name === '' || isset($names[$name])) {
                throw new InvalidArgumentException("Queue pool names must be non-empty and unique: {$name}.");
            }
            if ($minimum !== null && ! preg_match('/^\d+$/', $minimum)) {
                throw new InvalidArgumentException("Queue pool minimum must be a non-negative integer: {$entry}.");
            }

            $names[$name] = true;
            $workers = $minimum === null
                ? $activePrints
                : max($activePrints, (int) $minimum);
            $pools[] = [
                'name' => $name,
                'connection' => $connection,
                'queue' => $name,
                'workers' => $workers,
            ];
        }

        return $pools;
    }
}
