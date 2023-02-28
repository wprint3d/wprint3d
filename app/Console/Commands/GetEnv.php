<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GetEnv extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get:env
                            {variableName   : The name of the variable                            }
                            {--default=null : Default value (returned if the variable isn\'t set) }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get the value of a variable from the environment variables file (.env).';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $variableName = $this->argument('variableName');
        $default      = $this->option('default');

        switch ($default) {
            case 'null':    $default = null;  break;
            case 'true':    $default = true;  break;
            case 'false':   $default = false; break;
        }

        $output = env($variableName, $default);

        print ($output === true || $output === false)
            ? ($output ? 'true' : 'false')
            : $output;

        return Command::SUCCESS;
    }
}
