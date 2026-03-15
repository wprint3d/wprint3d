<?php

namespace App\Plugins;

use App\Events\PluginStateUpdated;
use App\Models\Plugin;

class PluginStateStore
{
    public function read(string $pluginId): array
    {
        $plugin = Plugin::where('plugin_id', $pluginId)->first();

        if (! $plugin || ! is_array($plugin->state)) {
            return [];
        }

        return $plugin->state;
    }

    public function publish(string $pluginId, array $data, bool $merge = true): array
    {
        $plugin = Plugin::where('plugin_id', $pluginId)->first();

        if (! $plugin) {
            return [];
        }

        $state = $merge
            ? $this->mergeState(is_array($plugin->state) ? $plugin->state : [], $data)
            : $data;

        $plugin->state = $state;
        $plugin->save();

        PluginStateUpdated::dispatch($pluginId, $state, now()->toAtomString());

        return $state;
    }

    private function mergeState(array $current, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && is_array($current[$key] ?? null)) {
                $current[$key] = $this->mergeState($current[$key], $value);
                continue;
            }

            $current[$key] = $value;
        }

        return $current;
    }
}
