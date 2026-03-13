<?php

namespace App\Console\Commands;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Console\Command;

class PluginDisable extends Command
{
    protected $signature = 'plugin:disable {pluginId}';
    protected $description = 'Disable an installed plugin';

    public function handle(PluginManager $pluginManager): int
    {
        $plugin = $pluginManager->disable($this->argument('pluginId'));

        $this->info("Disabled {$plugin['id']}");

        return self::SUCCESS;
    }
}
