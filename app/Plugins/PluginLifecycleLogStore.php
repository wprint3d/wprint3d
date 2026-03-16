<?php

namespace App\Plugins;

use App\Models\Plugin;

class PluginLifecycleLogStore
{
    public function list(string $pluginId): array
    {
        $plugin = Plugin::where('plugin_id', $pluginId)->first();

        if (! $plugin || ! is_array($plugin->logs)) {
            return [];
        }

        return array_values($plugin->logs);
    }

    public function append(Plugin $plugin, string $level, string $stage, string $message, array $context = []): array
    {
        $logs = is_array($plugin->logs) ? $plugin->logs : [];
        $logs[] = [
            'timestamp' => now()->toAtomString(),
            'level' => $level,
            'stage' => $stage,
            'message' => $message,
            'context' => $context,
        ];

        $maxEntries = max(10, (int) config('plugins.logs.max_entries', 200));

        $plugin->logs = array_values(array_slice($logs, -$maxEntries));
        $plugin->save();

        return $plugin->logs;
    }
}
