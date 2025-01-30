<?php

namespace App\Console\Commands;

use App\Models\Printer;

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
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $log = Log::channel('jobs-reset');

        foreach (Printer::select('hasActiveJob')->cursor() as $printer) {
            if (($printer->hasActiveJob ?? false) === false) {
                $log->debug("[{$printer->_id}] No active job detected, skipping.");

                continue;
            }

            $printer->hasActiveJob     = false;
            $printer->lastJobHasFailed = true;
            $printer->save();

            $log->info("[{$printer->_id}] Stalled job detected, resetting printer state.");
        }

        return Command::SUCCESS;
    }
}
