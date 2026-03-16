<?php

namespace App\Console\Commands;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Console\Command;

class PluginUpdate extends Command
{
    protected $signature = 'plugin:update {pluginId}';

    protected $description = 'Refresh or update an installed plugin from its configured source';

    public function handle(PluginManager $pluginManager): int
    {
        $plugin = $pluginManager->update($this->argument('pluginId'));
        $status = $plugin['updateStatus'] ?? 'updated';

        if ($status === 'noop') {
            $version = $plugin['latestVersion'] ?? $plugin['version'] ?? 'the current version';
            $this->info("No updates found for {$plugin['id']}. Already at {$version}.");

            return self::SUCCESS;
        }

        if ($status === 'unsupported') {
            $this->warn("No automatic update source is configured for {$plugin['id']}.");

            return self::SUCCESS;
        }

        if ($status === 'refreshed') {
            $this->info("Refreshed {$plugin['id']} from the live source mount.");

            return self::SUCCESS;
        }

        $this->info("Updated {$plugin['id']} to {$plugin['version']}");

        return self::SUCCESS;
    }
}
