<?php

namespace App\Services;

use App\Models\Printer;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PrintExecutionState
{
    public const VERSION = 1;

    public const HEARTBEAT_INTERVAL_SECS = 2;

    public function __construct(
        private readonly PrintStateTracker $tracker
    ) {}

    public function begin(Printer $printer, User $owner, string $uid, string $token): void
    {
        $machineFingerprint = (string) ($printer->machine['uuid'] ?? '');
        $context = [
            'version' => self::VERSION,
            'uid' => $uid,
            'ownerId' => (string) $owner->_id,
            'token' => $token,
            'machineFingerprint' => $machineFingerprint,
            'startedAt' => now()->toAtomString(),
        ];

        $printer->activePrintExecution = $context;
        $printer->save();

        $this->cache()->put($this->fenceKey($printer->_id), $token);
        $this->cache()->put($this->checkpointKey($printer->_id), [
            'version' => self::VERSION,
            'uid' => $uid,
            'token' => $token,
            'machineFingerprint' => $machineFingerprint,
            'ready' => false,
            'sourceCommandIndex' => 0,
            'displayedLine' => 0,
            'currentLayer' => 0,
            'state' => null,
            'pending' => null,
            'updatedAt' => microtime(true),
        ]);
        $this->heartbeat($printer->_id, $token);
    }

    public function markReady(
        string $printerId,
        string $token,
        array $position,
        array $statistics,
        int $displayedLine = 1
    ): array {
        $checkpoint = $this->requireCurrentCheckpoint($printerId, $token);
        $checkpoint['ready'] = true;
        $checkpoint['displayedLine'] = $displayedLine;
        $checkpoint['state'] = $this->tracker->initial($position, $statistics);
        $checkpoint['pending'] = null;
        $checkpoint['updatedAt'] = microtime(true);
        $this->cache()->put($this->checkpointKey($printerId), $checkpoint);

        return $checkpoint;
    }

    public function preparePending(
        string $printerId,
        string $token,
        string $command,
        bool $sourceCommand
    ): array {
        $checkpoint = $this->requireCurrentCheckpoint($printerId, $token);
        $prediction = $this->tracker->predict($checkpoint['state'], $command);
        $checkpoint['pending'] = [
            'command' => $command,
            'sourceCommand' => $sourceCommand,
            'beforeState' => $checkpoint['state'],
            'afterState' => $prediction['state'],
            'classification' => $prediction['classification'],
            'requiresPositionRefresh' => $prediction['requiresPositionRefresh'],
            'preparedAt' => microtime(true),
        ];
        $checkpoint['updatedAt'] = microtime(true);
        $this->cache()->put($this->checkpointKey($printerId), $checkpoint);

        return $checkpoint['pending'];
    }

    public function commitPending(
        string $printerId,
        string $token,
        array $statistics = [],
        ?array $observedPosition = null
    ): array {
        $checkpoint = $this->requireCurrentCheckpoint($printerId, $token);
        $pending = $checkpoint['pending'] ?? null;

        if (! is_array($pending)) {
            return $checkpoint;
        }

        $checkpoint['state'] = $this->tracker->withThermalSnapshot(
            $pending['afterState'],
            $statistics
        );

        if ($observedPosition !== null) {
            foreach (['x', 'y', 'z', 'e'] as $axis) {
                if (isset($observedPosition[$axis]) && is_numeric($observedPosition[$axis])) {
                    $checkpoint['state']['position'][$axis] = (float) $observedPosition[$axis];
                }
            }
        }

        if ($pending['sourceCommand']) {
            $checkpoint['sourceCommandIndex']++;
            $checkpoint['displayedLine'] = $checkpoint['sourceCommandIndex'] + 1;
        }

        $checkpoint['pending'] = null;
        $checkpoint['updatedAt'] = microtime(true);
        $this->cache()->put($this->checkpointKey($printerId), $checkpoint);

        return $checkpoint;
    }

    public function synchronizeObservedThermal(
        string $printerId,
        string $token,
        array $statistics,
        int $extruderIndex
    ): array {
        $checkpoint = $this->requireCurrentCheckpoint($printerId, $token);

        if (is_array($checkpoint['pending'] ?? null)) {
            throw new \RuntimeException(
                'Observed thermal state cannot be synchronized while a printer command is pending.'
            );
        }

        if (! is_array($checkpoint['state'] ?? null)) {
            throw new \RuntimeException('The print execution checkpoint is not ready.');
        }

        $checkpoint['state'] = $this->tracker->withObservedThermalSnapshot(
            $checkpoint['state'],
            $statistics,
            $extruderIndex
        );
        $checkpoint['updatedAt'] = microtime(true);
        $this->cache()->put($this->checkpointKey($printerId), $checkpoint);

        return $checkpoint;
    }

    public function checkpoint(string $printerId): ?array
    {
        $checkpoint = $this->cache()->get($this->checkpointKey($printerId));

        return is_array($checkpoint) ? $checkpoint : null;
    }

    public function hasResumableState(object $printer): bool
    {
        $context = $printer->activePrintExecution ?? null;

        if (! is_array($context) || ($context['version'] ?? null) !== self::VERSION) {
            return false;
        }

        $checkpoint = $this->checkpoint((string) $printer->_id);

        return is_array($checkpoint)
            && ($checkpoint['ready'] ?? false) === true
            && ($checkpoint['uid'] ?? null) === ($context['uid'] ?? null)
            && ($checkpoint['token'] ?? null) === ($context['token'] ?? null)
            && $this->isCurrent((string) $printer->_id, (string) ($context['token'] ?? ''));
    }

    public function hasRestartCandidate(object $printer): bool
    {
        $context = $printer->activePrintExecution ?? null;
        $checkpoint = $this->checkpoint((string) $printer->_id);

        return is_array($context)
            && ($context['version'] ?? null) === self::VERSION
            && is_array($checkpoint)
            && ($checkpoint['version'] ?? null) === self::VERSION
            && ($checkpoint['uid'] ?? null) === ($context['uid'] ?? null)
            && ($checkpoint['token'] ?? null) === ($context['token'] ?? null)
            && $this->isCurrent((string) $printer->_id, (string) ($context['token'] ?? ''));
    }

    public function isCurrent(string $printerId, string $token): bool
    {
        return $token !== '' && hash_equals(
            $token,
            (string) $this->cache()->get($this->fenceKey($printerId), '')
        );
    }

    public function rotate(Printer $printer): ?array
    {
        $context = $printer->activePrintExecution ?? null;

        if (! is_array($context) || ! $this->hasResumableState($printer)) {
            return null;
        }

        $token = (string) Str::uuid();
        $context['token'] = $token;
        $context['resumedAt'] = now()->toAtomString();

        $checkpoint = $this->checkpoint((string) $printer->_id);
        $checkpoint['token'] = $token;
        $checkpoint['updatedAt'] = microtime(true);
        $this->cache()->put($this->fenceKey($printer->_id), $token);
        $this->cache()->put($this->checkpointKey($printer->_id), $checkpoint);

        $printer->activePrintExecution = $context;
        $printer->save();

        $this->heartbeat((string) $printer->_id, $token);

        return $context;
    }

    public function heartbeat(string $printerId, string $token): bool
    {
        if (! $this->isCurrent($printerId, $token)) {
            return false;
        }

        $this->cache()->put($this->heartbeatKey($printerId), [
            'token' => $token,
            'at' => microtime(true),
        ]);

        return true;
    }

    public function heartbeatIsStale(string $printerId, string $token, int $staleAfterSecs): bool
    {
        $heartbeat = $this->cache()->get($this->heartbeatKey($printerId));

        return ! is_array($heartbeat)
            || ($heartbeat['token'] ?? null) !== $token
            || microtime(true) - (float) ($heartbeat['at'] ?? 0) > $staleAfterSecs;
    }

    public function clear(Printer $printer, ?string $token = null): bool
    {
        $printerId = (string) $printer->_id;

        if ($token !== null && ! $this->isCurrent($printerId, $token)) {
            return false;
        }

        $printer->activePrintExecution = null;
        $printer->save();

        $this->cache()->forget($this->checkpointKey($printerId));
        $this->cache()->forget($this->heartbeatKey($printerId));
        $this->cache()->forget($this->fenceKey($printerId));

        return true;
    }

    public function markRecovery(object $printer): void
    {
        $printerId = (string) $printer->_id;
        $checkpoint = $this->checkpoint($printerId);

        if (is_array($checkpoint) && isset($checkpoint['displayedLine'])) {
            $printer->lastLine = (int) $checkpoint['displayedLine'];
        }

        $printer->hasActiveJob = false;
        $printer->lastJobHasFailed = true;
        $printer->activePrintExecution = null;
        $printer->save();

        $this->cache()->forget($this->checkpointKey($printerId));
        $this->cache()->forget($this->heartbeatKey($printerId));
        $this->cache()->forget($this->fenceKey($printerId));
    }

    public function lock(string $printerId, int $seconds = 30)
    {
        return $this->cache()->lock('print-execution:reconcile:'.$printerId, $seconds);
    }

    private function requireCurrentCheckpoint(string $printerId, string $token): array
    {
        if (! $this->isCurrent($printerId, $token)) {
            throw new \RuntimeException('The print execution token is stale.');
        }

        $checkpoint = $this->checkpoint($printerId);

        if (! is_array($checkpoint) || ($checkpoint['token'] ?? null) !== $token) {
            throw new \RuntimeException('The print execution checkpoint is missing or stale.');
        }

        return $checkpoint;
    }

    private function cache(): Repository
    {
        return Cache::store(config('cache.print_execution_store', 'fast'));
    }

    private function checkpointKey(string $printerId): string
    {
        return 'print-execution:checkpoint:'.$printerId;
    }

    private function heartbeatKey(string $printerId): string
    {
        return 'print-execution:heartbeat:'.$printerId;
    }

    private function fenceKey(string $printerId): string
    {
        return 'print-execution:fence:'.$printerId;
    }
}
