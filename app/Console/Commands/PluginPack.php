<?php

namespace App\Console\Commands;

use App\Plugins\PluginPackager;
use Illuminate\Console\Command;

class PluginPack extends Command
{
    protected $signature = 'plugin:pack
        {source : Plugin source directory}
        {--output= : Output .w3dp path}
        {--signing-key= : PEM private key path used to sign the manifest}
        {--passphrase= : Private key passphrase}';

    protected $description = 'Build a .w3dp package from a plugin source directory';

    public function handle(PluginPackager $packager): int
    {
        $source = rtrim((string) $this->argument('source'), DIRECTORY_SEPARATOR);
        $output = $this->option('output') ?: $source . '.w3dp';

        $packager->build(
            sourceDirectory: $source,
            outputPath: $output,
            privateKeyPath: $this->option('signing-key') ?: null,
            passphrase: $this->option('passphrase') ?: null,
        );

        $this->info("Plugin package created at {$output}");

        return self::SUCCESS;
    }
}
