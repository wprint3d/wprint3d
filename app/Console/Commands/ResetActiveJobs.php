<?php

namespace App\Console\Commands;

use App\Models\Printer;

use Illuminate\Console\Command;

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
        foreach (Printer::all() as $printer) {
            if ($printer->hasActiveJob) {
                $printer->hasActiveJob     = false;
                $printer->lastJobHasFailed = true;
                $printer->save();
            }
        }

        return Command::SUCCESS;
    }
}
