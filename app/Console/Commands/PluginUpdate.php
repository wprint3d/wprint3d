<?php

namespace App\Console\Commands;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Console\Command;

class PluginUpdate extends Command
{
    protected $signature = 'plugin:update {pluginId}';
    protected $description = 'Update an installed plugin from the official registry';

    public function handle(PluginManager $pluginManager): int
    {
        $plugin = $pluginManager->update($this->argument('pluginId'));

        $this->info("Updated {$plugin['id']} to {$plugin['version']}");

        return self::SUCCESS;
    }
}
