<?php

namespace App\Plugins;

use App\Plugins\Exceptions\PluginRuntimeException;
use ZipArchive;

class PluginArchiveService
{
    public function __construct(
        private PluginManifestValidator $manifestValidator,
        private ?PluginSignatureService $signatureService = null,
        private ?string $packagesRoot = null,
        private ?PluginTrustedKeySynchronizer $trustedKeySynchronizer = null,
    ) {
        $this->signatureService ??= function_exists('app') && app()->bound(PluginSignatureService::class)
            ? app(PluginSignatureService::class)
            : new PluginSignatureService;
        $this->packagesRoot ??= $this->storagePath('app/plugins/packages');
        $this->trustedKeySynchronizer ??= function_exists('app') && app()->bound(PluginTrustedKeySynchronizer::class)
            ? app(PluginTrustedKeySynchronizer::class)
            : new PluginTrustedKeySynchronizer(signatureService: $this->signatureService);
    }

    public function inspect(string $archivePath, string $sourceType = 'local_upload'): PluginPackage
    {
        if (! is_file($archivePath)) {
            throw new PluginRuntimeException("Plugin archive does not exist: {$archivePath}");
        }

        $zip = $this->openArchive($archivePath);

        $rawManifest = $zip->getFromName('plugin.json');

        if (! is_string($rawManifest) || $rawManifest === '') {
            $zip->close();

            throw new PluginRuntimeException('Plugin archive is missing plugin.json.');
        }

        $manifest = json_decode($rawManifest, true);

        if (! is_array($manifest)) {
            $zip->close();

            throw new PluginRuntimeException('Plugin manifest is not valid JSON.');
        }

        $manifest = $this->manifestValidator->validate($manifest);
        $zip->close();

        $trustLevel = 'unsigned';
        $warnings = [];

        if (($manifest['signature']['algorithm'] ?? 'none') !== 'none') {
            $publicKeys = $this->trustedKeySynchronizer->allTrustedKeyPaths();
            $verified = $this->signatureService->verifyManifest($manifest, $publicKeys);
            $trustLevel = $verified ? 'signed' : 'invalid_signature';

            if (! $verified) {
                $warnings[] = 'Plugin signature could not be verified with the configured or synced trusted keys.';
            }
        }

        return new PluginPackage(
            manifest: $manifest,
            archivePath: $archivePath,
            archiveSha256: hash_file('sha256', $archivePath),
            sourceType: $sourceType,
            trustLevel: $trustLevel,
            warnings: $this->warningsForPackage($trustLevel, $warnings),
        );
    }

    public function inspectDirectory(string $directoryPath, string $sourceType = 'development_mount'): PluginPackage
    {
        if (! is_dir($directoryPath)) {
            throw new PluginRuntimeException("Plugin directory does not exist: {$directoryPath}");
        }

        $manifestPath = rtrim($directoryPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'plugin.json';

        if (! is_file($manifestPath)) {
            throw new PluginRuntimeException("Plugin directory is missing plugin.json: {$directoryPath}");
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            throw new PluginRuntimeException('Plugin manifest is not valid JSON.');
        }

        $manifest = $this->manifestValidator->validate($manifest);
        $trustLevel = $sourceType === 'development_mount' ? 'development' : 'unsigned';

        return new PluginPackage(
            manifest: $manifest,
            archivePath: $directoryPath,
            archiveSha256: hash_file('sha256', $manifestPath),
            sourceType: $sourceType,
            trustLevel: $trustLevel,
            warnings: $this->warningsForPackage($trustLevel),
        );
    }

    public function extract(string $archivePath, array $manifest): string
    {
        $targetPath = $this->packagesRoot.DIRECTORY_SEPARATOR.$manifest['id'].DIRECTORY_SEPARATOR.$manifest['version'];

        $this->deleteDirectory($targetPath);

        if (! @mkdir($targetPath, 0777, true) && ! is_dir($targetPath)) {
            throw new PluginRuntimeException("Unable to create plugin package directory: {$targetPath}");
        }

        $zip = $this->openArchive($archivePath);

        if (! $zip->extractTo($targetPath)) {
            $zip->close();

            throw new PluginRuntimeException("Unable to extract plugin archive to {$targetPath}");
        }

        $zip->close();

        return $targetPath;
    }

    private function openArchive(string $archivePath): ZipArchive
    {
        $zip = new ZipArchive;
        $result = $zip->open($archivePath);

        if ($result !== true) {
            throw new PluginRuntimeException("Failed to open plugin archive: {$archivePath}");
        }

        return $zip;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }

            $target = $path.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($target)) {
                $this->deleteDirectory($target);

                continue;
            }

            @unlink($target);
        }

        @rmdir($path);
    }

    private function storagePath(string $path): string
    {
        if (function_exists('storage_path')) {
            return storage_path($path);
        }

        return sys_get_temp_dir().DIRECTORY_SEPARATOR.trim($path, DIRECTORY_SEPARATOR);
    }

    private function warningsForPackage(string $trustLevel, array $warnings = []): array
    {
        if ($trustLevel === 'development') {
            return array_values(array_unique(array_merge([
                'This plugin is loaded directly from the development mount and updates live from source files.',
            ], $warnings)));
        }

        if ($trustLevel === 'unsigned') {
            return array_values(array_unique(array_merge([
                'This plugin is not signed. Treat it as a sideloaded package.',
            ], $warnings)));
        }

        return array_values(array_unique($warnings));
    }
}
