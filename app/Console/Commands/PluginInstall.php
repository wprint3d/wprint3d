<?php

namespace App\Console\Commands;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Console\Command;

class PluginInstall extends Command
{
    protected $signature = 'plugin:install
        {source : A local .w3dp path, a remote URL, or a registry plugin ID}
        {--plugin-version= : Explicit version when installing from the registry}
        {--registry : Force interpreting the source as an official registry plugin ID}';

    protected $description = 'Install a plugin from file, URL, or the official registry';

    public function handle(PluginManager $pluginManager): int
    {
        $source = (string) $this->argument('source');

        if ($this->option('registry')) {
            $plugin = $pluginManager->installFromRegistry($source, $this->option('plugin-version'));
            $this->info("Installed {$plugin['id']} from the official registry.");

            return self::SUCCESS;
        }

        if (is_file($source)) {
            $plugin = $pluginManager->installFromArchive($source, 'local_file', ['path' => realpath($source)]);
            $this->info("Installed {$plugin['id']} from a local file.");

            return self::SUCCESS;
        }

        if (filter_var($source, FILTER_VALIDATE_URL)) {
            $plugin = $pluginManager->installFromUrl($source);
            $this->info("Installed {$plugin['id']} from URL.");

            return self::SUCCESS;
        }

        $plugin = $pluginManager->installFromRegistry($source, $this->option('plugin-version'));
        $this->info("Installed {$plugin['id']} from the official registry.");

        return self::SUCCESS;
    }
}
