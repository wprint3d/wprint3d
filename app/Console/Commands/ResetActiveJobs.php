<?php

namespace App\Console\Commands;

use App\Models\Printer;
use App\Services\PrintExecutionState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResetActiveJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:active-jobs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Iterate through all available printers and disable all their active jobs. This is meant to be run on boot in order to detect inconsistent states: power outages, kernel panics, etc.';

    /**
     * Retrieve the printers that should be reconciled at startup.
     */
    protected function getPrinters(): iterable
    {
        return Printer::select(
            'hasActiveJob',
            'activeFile',
            'lastLine',
            'activePrintExecution'
        )->cursor();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $log = Log::channel('jobs-reset');
        $executionState = app(PrintExecutionState::class);

        foreach ($this->getPrinters() as $printer) {
            if (($printer->hasActiveJob ?? false) === false) {
                $log->debug("[{$printer->_id}] No active job detected, skipping.");

                continue;
            }

            if ($executionState->hasRestartCandidate($printer)) {
                $log->info("[{$printer->_id}] Resumable active job detected; leaving it for connection reconciliation.");

                continue;
            }

            $executionState->markRecovery($printer);

            $log->info("[{$printer->_id}] Stalled job detected, resetting printer state.");
        }

        return Command::SUCCESS;
    }
}
