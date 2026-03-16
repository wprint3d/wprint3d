<?php

namespace App\Console\Commands;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Console\Command;

class PluginAutoUpdate extends Command
{
    protected $signature = 'plugin:auto-update';

    protected $description = 'Check for and apply automatic updates for eligible plugins';

    public function handle(PluginManager $pluginManager): int
    {
        $summary = $pluginManager->runAutomaticUpdates();

        $this->info(sprintf(
            'Automatic plugin updates checked %d plugins: %d updated, %d already current, %d skipped, %d failed.',
            (int) ($summary['checkedCount'] ?? 0),
            (int) ($summary['updatedCount'] ?? 0),
            (int) ($summary['noopCount'] ?? 0),
            (int) ($summary['skippedCount'] ?? 0),
            (int) ($summary['failedCount'] ?? 0),
        ));

        return self::SUCCESS;
    }
}
