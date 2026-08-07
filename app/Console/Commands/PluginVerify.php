<?php

namespace App\Console\Commands;

use App\Plugins\PluginArchiveService;
use App\Plugins\PluginSignatureService;
use Illuminate\Console\Command;

class PluginVerify extends Command
{
    protected $signature = 'plugin:verify
        {package : Local .w3dp package path}
        {--write-public-key= : Optional PEM output path for the embedded public key}
        {--require-trusted : Fail unless the package is trusted by this WPrint 3D instance}';

    protected $description = 'Verify a plugin package using its embedded signature and this instance\'s trusted keys';

    public function handle(PluginArchiveService $archiveService, PluginSignatureService $signatureService): int
    {
        $packagePath = (string) $this->argument('package');
        $package = $archiveService->inspect($packagePath, 'verify');
        $manifest = $package->manifest;
        $archiveService->verifyIntegrity($packagePath, $manifest);
        $signedManifest = $package->rawManifest ?? $manifest;
        $signature = $signedManifest['signature'] ?? [];
        $algorithm = $signature['algorithm'] ?? 'none';
        $embeddedPublicKey = $signatureService->embeddedPublicKey($signedManifest);

        $embeddedStatus = 'unsigned';
        $embeddedValid = true;

        if ($algorithm !== 'none') {
            $embeddedValid = $embeddedPublicKey !== null
                && $signatureService->verifyManifestWithPublicKeyContents($signedManifest, $embeddedPublicKey);
            $embeddedStatus = $embeddedValid ? 'valid' : 'invalid';
        }

        if ($embeddedPublicKey && $this->option('write-public-key')) {
            file_put_contents((string) $this->option('write-public-key'), $embeddedPublicKey);
            $this->line('Embedded public key written to '.(string) $this->option('write-public-key'));
        }

        $trusted = $package->trustLevel === 'signed';

        $this->line('Plugin: '.($manifest['id'] ?? 'unknown'));
        $this->line('Version: '.($manifest['version'] ?? 'unknown'));
        $this->line('Embedded signature: '.$embeddedStatus);
        $this->line('Trusted by this WPrint 3D instance: '.($trusted ? 'yes' : 'no'));

        foreach ($package->warnings as $warning) {
            $this->warn($warning);
        }

        if (! $embeddedValid) {
            $this->error('The package signature is invalid when checked against its embedded public key.');

            return self::FAILURE;
        }

        if ($this->option('require-trusted') && ! $trusted) {
            $this->error('The package is not trusted by this WPrint 3D instance.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
