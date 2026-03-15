<?php

namespace App\Console\Commands;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Console\Command;

class PluginDoctor extends Command
{
    protected $signature = 'plugin:doctor {--safe-mode : Disable all enabled plugins after printing diagnostics}';

    protected $description = 'Run plugin health diagnostics';

    public function handle(PluginManager $pluginManager): int
    {
        $results = $pluginManager->doctor();

        $this->table(
            ['ID', 'Enabled', 'Status', 'Runtime Path', 'Trust', 'Warnings', 'Last error'],
            collect($results)->map(fn ($result) => [
                $result['id'],
                $result['enabled'] ? 'yes' : 'no',
                $result['loadStatus'] ?? 'unknown',
                $result['runtimePathExists'] ? 'ok' : 'missing',
                $result['trustLevel'],
                implode('; ', $result['warnings']),
                $result['lastError'] ?? '',
            ])->all()
        );

        if ($this->option('safe-mode')) {
            $count = $pluginManager->safeModeDisableAll();
            $this->warn("Safe mode disabled {$count} plugins.");
        }

        return self::SUCCESS;
    }
}
