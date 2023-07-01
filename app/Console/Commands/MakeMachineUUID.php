<?php

namespace App\Console\Commands;

use App\Models\Configuration;

use Illuminate\Console\Command;

use Illuminate\Support\Str;

class MakeMachineUUID extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:machine-uuid';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the current machine (host) UUID.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $configuration = Configuration::where('key', 'machineUUID')->first();

        if (!$configuration) {
            $configuration = new Configuration();
        }

        $configuration->key     = 'machineUUID';
        $configuration->value   = Str::uuid()->toString();
        $configuration->save();

        echo $configuration->value;

        return Command::SUCCESS;
    }
}
