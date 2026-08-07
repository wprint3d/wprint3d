<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use App\Plugins\PluginDependencyService;
use Illuminate\Console\Command;

class PluginReconcileRuntime extends Command
{
    protected $signature = 'plugin:reconcile-runtime';

    protected $description = 'Reconcile enabled heavyweight plugin containers after startup.';

    public function handle(PluginDependencyService $dependencyService): int
    {
        $results = $dependencyService->reconcile(Plugin::query()->where('enabled', true)->get()->map(fn (Plugin $plugin) => [
            'id' => $plugin->plugin_id,
            'enabled' => true,
            'manifest' => is_array($plugin->manifest) ? $plugin->manifest : [],
            'dependency_state' => is_array($plugin->dependency_state) ? $plugin->dependency_state : [],
        ])->all());

        foreach ($results as $result) {
            if (($result['status'] ?? null) === 'failed') {
                $this->error(($result['id'] ?? 'plugin').': '.($result['error'] ?? 'reconciliation failed'));

                continue;
            }

            $this->info(($result['id'] ?? 'plugin').': ready');
        }

        return collect($results)->contains(fn (array $result) => ($result['status'] ?? null) === 'failed')
            ? self::FAILURE
            : self::SUCCESS;
    }
}
