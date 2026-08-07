<?php

namespace App\Plugins;

use App\Plugins\Exceptions\PluginRuntimeException;
use Illuminate\Support\Str;
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

        $trust = $this->resolveTrustLevel($manifest, $sourceType);
        $manifest = $this->manifestValidator->validate($manifest);
        $zip->close();

        return new PluginPackage(
            manifest: $manifest,
            rawManifest: $trust['rawManifest'],
            archivePath: $archivePath,
            archiveSha256: hash_file('sha256', $archivePath),
            sourceType: $sourceType,
            trustLevel: $trust['trustLevel'],
            warnings: $this->warningsForPackage($trust['trustLevel'], $trust['warnings']),
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

        $trust = $this->resolveTrustLevel($manifest, $sourceType);
        $manifest = $this->manifestValidator->validate($manifest);

        return new PluginPackage(
            manifest: $manifest,
            rawManifest: $trust['rawManifest'],
            archivePath: $directoryPath,
            archiveSha256: hash_file('sha256', $manifestPath),
            sourceType: $sourceType,
            trustLevel: $trust['trustLevel'],
            warnings: $this->warningsForPackage($trust['trustLevel'], $trust['warnings']),
        );
    }

    private function resolveTrustLevel(array $manifest, string $sourceType): array
    {
        if ($sourceType === 'development_mount') {
            return [
                'rawManifest' => $manifest,
                'trustLevel' => 'development',
                'warnings' => [],
            ];
        }

        if (($manifest['signature']['algorithm'] ?? 'none') === 'none') {
            return [
                'rawManifest' => $manifest,
                'trustLevel' => 'unsigned',
                'warnings' => [],
            ];
        }

        $publicKeys = $this->trustedKeySynchronizer->allTrustedKeyPaths();
        $verified = $this->signatureService->verifyManifest($manifest, $publicKeys);

        return [
            'rawManifest' => $manifest,
            'trustLevel' => $verified ? 'signed' : 'invalid_signature',
            'warnings' => $verified
                ? []
                : ['Plugin signature could not be verified with the configured or synced trusted keys.'],
        ];
    }

    public function extract(string $archivePath, array $manifest): string
    {
        $targetPath = $this->packagesRoot.DIRECTORY_SEPARATOR.$manifest['id'].DIRECTORY_SEPARATOR.$manifest['version'];

        return $this->extractArchive($archivePath, $targetPath, true, $manifest);
    }

    public function verifyIntegrity(string $archivePath, array $manifest): void
    {
        $targetPath = $this->packagesRoot.DIRECTORY_SEPARATOR.'verification'.DIRECTORY_SEPARATOR.Str::uuid()->toString();

        try {
            $this->extractArchive($archivePath, $targetPath, true, $manifest);
        } finally {
            $this->deleteDirectory($targetPath);
        }
    }

    public function restoreToDirectory(string $archivePath, string $targetPath, bool $overwrite = false): string
    {
        $result = $this->extractArchive($archivePath, $targetPath, $overwrite);
        $manifestPath = $result.DIRECTORY_SEPARATOR.'plugin.json';
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
        if (is_array($manifest)) {
            $this->assertAssetIntegrity($result, $manifest);
        }

        return $result;
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

    private function extractArchive(string $archivePath, string $targetPath, bool $overwrite, ?array $manifest = null): string
    {
        if ($overwrite) {
            $this->deleteDirectory($targetPath);
        } elseif (is_dir($targetPath) && (scandir($targetPath) ?: []) !== ['.', '..']) {
            throw new PluginRuntimeException("Target restore directory already exists: {$targetPath}");
        }

        if (! @mkdir($targetPath, 0777, true) && ! is_dir($targetPath)) {
            throw new PluginRuntimeException("Unable to create plugin package directory: {$targetPath}");
        }

        $zip = $this->openArchive($archivePath);

        $this->assertSafeArchiveEntries($zip);

        if (! $zip->extractTo($targetPath)) {
            $zip->close();

            throw new PluginRuntimeException("Unable to extract plugin archive to {$targetPath}");
        }

        $zip->close();

        if ($manifest !== null) {
            try {
                $this->assertAssetIntegrity($targetPath, $manifest);
            } catch (\Throwable $exception) {
                $this->deleteDirectory($targetPath);
                throw $exception;
            }
        }

        return $targetPath;
    }

    /**
     * Verify deterministic aggregate hashes generated by the W3DP staging tool.
     * Older manifests without integrity metadata remain compatible.
     */
    public function assertAssetIntegrity(string $directory, array $manifest): void
    {
        foreach ($manifest['assets'] ?? [] as $asset) {
            if (! is_array($asset) || ! is_string($asset['path'] ?? null)) {
                continue;
            }

            $expected = $asset['integrity'] ?? null;
            if (! is_string($expected) || ! str_starts_with($expected, 'sha256-')) {
                continue;
            }

            $relativeAsset = trim(str_replace('\\', '/', $asset['path']), '/');
            if ($relativeAsset === '' || str_contains($relativeAsset, "\0") || str_contains('/'.$relativeAsset.'/', '/../')) {
                throw new PluginRuntimeException('Plugin asset integrity path is unsafe.');
            }

            $assetRoot = realpath($directory.DIRECTORY_SEPARATOR.$relativeAsset);
            $root = realpath($directory);
            if ($root === false || $assetRoot === false || ! str_starts_with($assetRoot, $root.DIRECTORY_SEPARATOR)) {
                throw new PluginRuntimeException("Plugin asset directory is missing: {$relativeAsset}");
            }

            $files = [];
            if (is_file($assetRoot)) {
                $files[] = [$assetRoot, basename($assetRoot)];
            } else {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($assetRoot, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if (! $file->isFile() || $file->isLink()) {
                        continue;
                    }
                    $path = $file->getPathname();
                    $relative = ltrim(str_replace($assetRoot, '', $path), DIRECTORY_SEPARATOR);
                    $files[] = [$path, str_replace(DIRECTORY_SEPARATOR, '/', $relative)];
                }
            }

            usort($files, static fn (array $left, array $right): int => strcmp($left[1], $right[1]));
            $context = hash_init('sha256');
            foreach ($files as [$path, $relative]) {
                hash_update($context, $relative."\0");
                hash_update_file($context, $path);
            }
            $actual = 'sha256-'.base64_encode(hex2bin(hash_final($context)));
            if (! hash_equals($expected, $actual)) {
                throw new PluginRuntimeException("Plugin asset integrity mismatch: {$relativeAsset}");
            }
        }

        $fileIntegrity = $manifest['integrity']['files'] ?? [];
        if (is_array($fileIntegrity)) {
            $root = realpath($directory);
            if ($root === false) {
                throw new PluginRuntimeException('Plugin integrity root is missing.');
            }
            $actualAssetFiles = [];
            foreach ($manifest['assets'] ?? [] as $asset) {
                if (! is_array($asset) || ! is_string($asset['path'] ?? null)) {
                    continue;
                }
                $relativeAsset = trim(str_replace('\\', '/', $asset['path']), '/');
                $assetRoot = realpath($directory.DIRECTORY_SEPARATOR.$relativeAsset);
                if ($assetRoot === false || ! str_starts_with($assetRoot, $root.DIRECTORY_SEPARATOR)) {
                    continue;
                }
                if (is_file($assetRoot)) {
                    $actualAssetFiles[] = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(str_replace($root, '', $assetRoot), DIRECTORY_SEPARATOR));

                    continue;
                }
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($assetRoot, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if ($file->isLink()) {
                        throw new PluginRuntimeException('Plugin integrity assets cannot contain symbolic links.');
                    }
                    if ($file->isFile()) {
                        $actualAssetFiles[] = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR));
                    }
                }
            }
            $declaredAssetFiles = array_map(
                static fn ($path): string => str_replace('\\', '/', (string) $path),
                array_keys($fileIntegrity),
            );
            if ($fileIntegrity !== [] && array_diff($actualAssetFiles, $declaredAssetFiles) !== []) {
                throw new PluginRuntimeException('Plugin integrity map does not declare every staged asset file.');
            }
            if ($fileIntegrity !== [] && array_diff($declaredAssetFiles, $actualAssetFiles) !== []) {
                throw new PluginRuntimeException('Plugin integrity map declares a file outside staged assets.');
            }
            foreach ($fileIntegrity as $relativePath => $expected) {
                $relativePath = str_replace('\\', '/', (string) $relativePath);
                if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains('/'.$relativePath.'/', '/../') || str_contains($relativePath, "\0")) {
                    throw new PluginRuntimeException('Plugin integrity file path is unsafe.');
                }
                $path = realpath($directory.DIRECTORY_SEPARATOR.$relativePath);
                if ($path === false || ! is_file($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                    throw new PluginRuntimeException("Plugin integrity file is missing: {$relativePath}");
                }
                $actual = hash_file('sha256', $path);
                if (! is_string($expected) || ! hash_equals(strtolower($expected), strtolower((string) $actual))) {
                    throw new PluginRuntimeException("Plugin file integrity mismatch: {$relativePath}");
                }
            }
        }
    }

    private function assertSafeArchiveEntries(ZipArchive $zip): void
    {
        $maxEntries = (int) config('plugins.archive.max_entries', 2048);
        $maxUncompressedBytes = (int) config('plugins.archive.max_uncompressed_bytes', 250 * 1024 * 1024);
        $totalUncompressedBytes = 0;

        if ($zip->numFiles > $maxEntries) {
            throw new PluginRuntimeException('Plugin archive contains too many entries.');
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            $name = (string) ($entry['name'] ?? '');
            $normalized = str_replace('\\', '/', $name);
            $uncompressedSize = (int) ($entry['size'] ?? 0);
            $compressedSize = max(1, (int) ($entry['comp_size'] ?? 0));
            $totalUncompressedBytes += $uncompressedSize;

            if ($totalUncompressedBytes > $maxUncompressedBytes || ($uncompressedSize > 1024 * 1024 && $uncompressedSize / $compressedSize > 1000)) {
                throw new PluginRuntimeException('Plugin archive exceeds the safe extraction limits.');
            }

            if ($normalized === '' || str_contains($normalized, "\0") || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:\//', $normalized)) {
                throw new PluginRuntimeException('Plugin archive contains an unsafe entry path.');
            }

            $parts = array_values(array_filter(explode('/', $normalized), static fn (string $part): bool => $part !== ''));
            if (in_array('..', $parts, true)) {
                throw new PluginRuntimeException('Plugin archive contains a path traversal entry.');
            }

            $mode = ((int) ($entry['external_attributes'] ?? 0) >> 16) & 0xF000;
            if ($mode === 0xA000) {
                throw new PluginRuntimeException('Plugin archive symlinks are not allowed.');
            }
        }
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
