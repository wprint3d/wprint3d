<?php

namespace App\Console\Commands;

use App\Models\Printer;

use Illuminate\Console\Command;

class GetMinWorkers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get:min-workers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get the value of the minimum amount of workers required for the operation of the software.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        echo Printer::where('activeFile', '!=', null)->count();

        return Command::SUCCESS;
    }
}
