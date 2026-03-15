<?php

namespace App\Console\Commands;

use App\Plugins\PluginArchiveService;
use App\Plugins\PluginSignatureService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PluginRestore extends Command
{
    protected $signature = 'plugin:restore
        {package : Local .w3dp package path}
        {--output= : Destination directory for the restored plugin source}
        {--force : Overwrite the destination directory if it already exists}
        {--require-trusted : Fail unless the package is trusted by this WPrint 3D instance}';

    protected $description = 'Restore a plugin source tree from a .w3dp package';

    public function handle(PluginArchiveService $archiveService, PluginSignatureService $signatureService): int
    {
        $packagePath = (string) $this->argument('package');
        $package = $archiveService->inspect($packagePath, 'restore');
        $manifest = $package->manifest;
        $signedManifest = $package->rawManifest ?? $manifest;
        $signature = $signedManifest['signature'] ?? [];
        $algorithm = $signature['algorithm'] ?? 'none';
        $embeddedPublicKey = $signatureService->embeddedPublicKey($signedManifest);

        if ($algorithm !== 'none') {
            $embeddedValid = $embeddedPublicKey !== null
                && $signatureService->verifyManifestWithPublicKeyContents($signedManifest, $embeddedPublicKey);

            if (! $embeddedValid) {
                $this->error('The package signature is invalid when checked against its embedded public key.');

                return self::FAILURE;
            }
        }

        if ($this->option('require-trusted') && $package->trustLevel !== 'signed') {
            $this->error('The package is not trusted by this WPrint 3D instance.');

            return self::FAILURE;
        }

        foreach ($package->warnings as $warning) {
            $this->warn($warning);
        }

        if ($algorithm === 'none') {
            $this->warn('The package is unsigned. Restore is continuing because no embedded signature is available to validate.');
        }

        $output = (string) ($this->option('output') ?: $this->defaultRestorePath((string) ($manifest['id'] ?? 'plugin')));
        $restoredPath = $archiveService->restoreToDirectory($packagePath, $output, (bool) $this->option('force'));

        $this->info("Restored plugin source to {$restoredPath}");

        return self::SUCCESS;
    }

    private function defaultRestorePath(string $pluginId): string
    {
        $slug = Str::slug(str_replace(['.', '_'], ' ', $pluginId));

        return base_path('plugins'.DIRECTORY_SEPARATOR.($slug !== '' ? $slug : 'restored-plugin'));
    }
}
