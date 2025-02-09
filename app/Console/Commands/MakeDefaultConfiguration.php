<?php

namespace App\Console\Commands;

use App\Models\Configuration;

use Illuminate\Console\Command;

class MakeDefaultConfiguration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:default-configuration';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the default configuration values.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle() {
        $defaults = config('system.defaults');

        foreach ($defaults as $key => $value) {
            $configuration = Configuration::where('key', $key)->first();

            if (!$configuration) {
                $configuration = new Configuration();
                $configuration->value = $value['value'];
            }

            $configuration->key         = $key;
            $configuration->default     = $value['value'];
            $configuration->hint        = $value['hint'];
            $configuration->type        = $value['type'];
            $configuration->section     = $value['section'];
            $configuration->description = $value['description'];
            $configuration->visible     = $value['visible']    ?? true;
            $configuration->writeable   = $value['writeable']  ?? true;
            $configuration->enum        = $value['enum']       ?? null;
            $configuration->save();

            if (isset($configuration->_id)) {
                $this->info("Updated configuration key: {$key}");
            } else {
                $this->info("Created configuration key: {$key}");
            }
        }

        return Command::SUCCESS;
    }
}
