<?php

namespace App\Console\Commands;

use App\Plugins\Exceptions\PluginRuntimeException;
use App\Plugins\PluginPackager;
use Illuminate\Console\Command;

class PluginPack extends Command
{
    protected $signature = 'plugin:pack
        {source : Plugin source directory}
        {--output= : Output .w3dp path}
        {--signing-key= : PEM private key path used to sign the manifest}
        {--passphrase= : Private key passphrase}
        {--passphrase-file= : File containing the private key passphrase}';

    protected $description = 'Build a .w3dp package from a plugin source directory';

    public function handle(PluginPackager $packager): int
    {
        $source = rtrim((string) $this->argument('source'), DIRECTORY_SEPARATOR);
        $output = $this->option('output') ?: $this->defaultOutputPath($source);
        $passphrase = $this->resolvePassphrase();

        $packager->build(
            sourceDirectory: $source,
            outputPath: $output,
            privateKeyPath: $this->option('signing-key') ?: null,
            passphrase: $passphrase,
        );

        $this->info("Plugin package created at {$output}");

        return self::SUCCESS;
    }

    private function resolvePassphrase(): ?string
    {
        $passphrase = $this->option('passphrase');
        $passphraseFile = $this->option('passphrase-file');

        if ($passphrase !== null && $passphraseFile !== null) {
            throw new PluginRuntimeException('Use either --passphrase or --passphrase-file, not both.');
        }

        if ($passphraseFile === null || $passphraseFile === '') {
            return $passphrase ?: null;
        }

        if (! is_file((string) $passphraseFile) || ! is_readable((string) $passphraseFile)) {
            throw new PluginRuntimeException('Plugin signing passphrase file is missing or unreadable.');
        }

        return rtrim((string) file_get_contents((string) $passphraseFile), "\r\n");
    }

    private function defaultOutputPath(string $source): string
    {
        return $source
            .DIRECTORY_SEPARATOR
            .'builds'
            .DIRECTORY_SEPARATOR
            .basename($source)
            .'.w3dp';
    }
}
