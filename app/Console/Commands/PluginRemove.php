<?php

namespace App\Console\Commands;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Console\Command;

class PluginRemove extends Command
{
    protected $signature = 'plugin:remove {pluginId}';
    protected $description = 'Remove an installed plugin';

    public function handle(PluginManager $pluginManager): int
    {
        $pluginManager->uninstall($this->argument('pluginId'));

        $this->info('Plugin removed.');

        return self::SUCCESS;
    }
}
