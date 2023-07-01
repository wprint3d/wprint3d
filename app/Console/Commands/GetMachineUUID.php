<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GetMachineUUID extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get:machine-uuid';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get the current machine (host) UUID.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        echo machineUUID();

        return Command::SUCCESS;
    }
}
