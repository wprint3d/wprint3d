<?php

namespace App\Console\Commands;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Console\Command;

class PluginEnable extends Command
{
    protected $signature = 'plugin:enable {pluginId}';
    protected $description = 'Enable an installed plugin';

    public function handle(PluginManager $pluginManager): int
    {
        $plugin = $pluginManager->enable($this->argument('pluginId'));

        $this->info("Enabled {$plugin['id']} ({$plugin['version']})");

        return self::SUCCESS;
    }
}
