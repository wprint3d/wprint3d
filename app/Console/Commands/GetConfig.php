<?php

namespace App\Console\Commands;

use App\Models\Configuration;

use Illuminate\Console\Command;

class GetConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get:config
                            { key            : The key within the configuration collection         }
                            { --default=null : Default value (returned if the variable isn\'t set) }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get the value of a configuration key, if it\'s not set, return a variable from the environment variables file (.env) instead.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $key     = $this->argument('key');
        $default = $this->option('default');

        switch ($default) {
            case 'null':    $default = null;  break;
            case 'true':    $default = true;  break;
            case 'false':   $default = false; break;
        }

        $output = Configuration::get($key, $default);

        print ($output === true || $output === false)
            ? ($output ? 'true' : 'false')
            : $output;

        return Command::SUCCESS;
    }
}
