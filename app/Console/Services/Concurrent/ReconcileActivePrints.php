<?php

namespace App\Console\Services\Concurrent;

use App\Console\Services\Concurrent\Dependencies\ConcurrentService;
use App\Events\PrintJobFailed;
use App\Jobs\PrintGcode;
use App\Models\Configuration;
use App\Models\Printer;
use App\Models\User;
use App\Plugins\PluginHookDispatcher;
use App\Services\PrintExecutionState;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileActivePrints extends ConcurrentService
{
    protected $description = 'Resume active prints whose queue worker stopped producing heartbeats.';

    private ?Logger $log = null;

    public function handle(): void
    {
        while (true) {
            try {
                $threshold = max(
                    15,
                    2 * (int) Configuration::get('lastSeenThresholdSecs')
                );

                $this->reconcilePrinters(
                    Printer::where('hasActiveJob', true)->where('activeFile', '!=', null)->cursor(),
                    $threshold
                );
            } catch (Throwable $exception) {
                $this->logger()->error(
                    "Couldn't reconcile active prints: {$exception->getMessage()}".PHP_EOL.
                    $exception->getTraceAsString()
                );
            }

            sleep(PrintExecutionState::HEARTBEAT_INTERVAL_SECS);
        }
    }

    protected function reconcilePrinters(iterable $printers, int $staleAfterSecs): void
    {
        $executionState = app(PrintExecutionState::class);

        foreach ($printers as $printer) {
            if (! $printer->hasActivePrintJob()) {
                continue;
            }

            $context = $printer->activePrintExecution ?? null;

            if (! is_array($context) || ! $executionState->hasRestartCandidate($printer)) {
                $this->transitionToRecovery($printer, 'The active print has no resumable execution checkpoint.');

                continue;
            }

            $token = (string) ($context['token'] ?? '');

            if (! $executionState->heartbeatIsStale((string) $printer->_id, $token, $staleAfterSecs)) {
                continue;
            }

            $lock = $executionState->lock((string) $printer->_id);

            if (! $lock->get()) {
                continue;
            }

            try {
                $printer->refresh();
                $context = $printer->activePrintExecution ?? null;

                if (
                    ! $printer->hasActivePrintJob()
                    || ! is_array($context)
                    || ! $executionState->hasRestartCandidate($printer)
                ) {
                    continue;
                }

                $token = (string) ($context['token'] ?? '');

                if (! $executionState->heartbeatIsStale((string) $printer->_id, $token, $staleAfterSecs)) {
                    continue;
                }

                if (($executionState->checkpoint((string) $printer->_id)['ready'] ?? false) !== true) {
                    $this->transitionToRecovery(
                        $printer,
                        'The print worker stopped before establishing a safe physical checkpoint.'
                    );

                    continue;
                }

                $owner = User::find($context['ownerId'] ?? null);

                if (! $owner) {
                    $this->transitionToRecovery($printer, 'The print owner no longer exists.');

                    continue;
                }

                $rotatedContext = $executionState->rotate($printer);

                if (! is_array($rotatedContext)) {
                    $this->transitionToRecovery($printer, 'The print execution could not be fenced for resumption.');

                    continue;
                }

                PrintGcode::dispatch(
                    $owner,
                    (string) $printer->_id,
                    (string) $rotatedContext['uid'],
                    (string) $rotatedContext['token']
                );

                $this->logger()->warning(
                    "[{$printer->_id}] Stale print heartbeat detected; dispatched a fenced continuation worker."
                );
            } finally {
                $lock->release();
            }
        }
    }

    protected function transitionToRecovery(object $printer, string $reason): void
    {
        $context = $printer->activePrintExecution ?? [];
        app(PrintExecutionState::class)->markRecovery($printer);

        try {
            PrintJobFailed::dispatch($printer->_id);
            app(PluginHookDispatcher::class)->dispatch('print.job.failed', [
                'printerId' => $printer->_id,
                'filePath' => $printer->activeFile,
                'jobUid' => $context['uid'] ?? null,
                'message' => $reason,
            ]);
        } catch (Throwable $exception) {
            $this->logger()->warning(
                "[{$printer->_id}] Recovery state was saved but its event could not be dispatched: {$exception->getMessage()}"
            );
        }

        $this->logger()->warning("[{$printer->_id}] {$reason} Recovery is now pending.");
    }

    private function logger(): Logger
    {
        return $this->log ??= Log::channel('gcode-printer');
    }
}
