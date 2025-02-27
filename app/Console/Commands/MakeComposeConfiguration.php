<?php

namespace App\Console\Commands;

use App\Models\Configuration;

use Illuminate\Console\Command;

class MakeComposeConfiguration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:compose-path-config';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the configuration file for the compose path';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle() {
        $composeDir = env('COMPOSE_DIR');

        if (!$composeDir) {
            $this->error('COMPOSE_DIR is not set within the environment');

            return Command::FAILURE;
        }

        $configContent = <<<PHP
        <?php

        return [
            'compose_dir' => '{$composeDir}',
        ];
        PHP;

        file_put_contents(config_path('docker.php'), $configContent);

        $this->info('docker.php created with compose_path set to: ' . $composeDir);

        return Command::SUCCESS;
    }
}
