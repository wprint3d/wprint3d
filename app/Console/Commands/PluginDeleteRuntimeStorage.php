<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use App\Plugins\PluginDependencyService;
use Illuminate\Console\Command;

class PluginDeleteRuntimeStorage extends Command
{
    protected $signature = 'plugin:delete-runtime-storage {pluginId} {--confirm : Confirm permanent deletion of retained runtime data}';

    protected $description = 'Permanently delete named-volume data retained by a disabled heavyweight plugin.';

    public function handle(PluginDependencyService $dependencyService): int
    {
        if (! $this->option('confirm')) {
            $this->error('Refusing to delete runtime storage without --confirm.');

            return self::INVALID;
        }

        $plugin = Plugin::where('plugin_id', (string) $this->argument('pluginId'))->first();

        if (! $plugin) {
            $this->error('Plugin is not installed.');

            return self::FAILURE;
        }

        if ($plugin->enabled) {
            $this->error('Disable the plugin before deleting its retained runtime storage.');

            return self::FAILURE;
        }

        $deleted = $dependencyService->deletePersistentStorage([
            'id' => $plugin->plugin_id,
            'manifest' => is_array($plugin->manifest) ? $plugin->manifest : [],
        ]);
        $this->info($deleted === [] ? 'No retained runtime volumes were found.' : 'Deleted: '.implode(', ', $deleted));

        return self::SUCCESS;
    }
}
