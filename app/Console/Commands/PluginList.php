<?php

namespace App\Console\Commands;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Console\Command;

class PluginList extends Command
{
    protected $signature = 'plugin:list';
    protected $description = 'List installed plugins';

    public function handle(PluginManager $pluginManager): int
    {
        $plugins = $pluginManager->listInstalled();

        $this->table(
            ['ID', 'Name', 'Version', 'Enabled', 'Trust'],
            collect($plugins)->map(fn ($plugin) => [
                $plugin['id'],
                $plugin['name'],
                $plugin['version'],
                $plugin['enabled'] ? 'yes' : 'no',
                $plugin['trustLevel'],
            ])->all()
        );

        return self::SUCCESS;
    }
}
