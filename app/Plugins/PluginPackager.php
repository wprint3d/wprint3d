<?php

namespace App\Plugins;

use App\Plugins\Exceptions\PluginRuntimeException;
use ZipArchive;

class PluginPackager
{
    public function __construct(
        private PluginManifestValidator $manifestValidator,
        private PluginSignatureService $signatureService,
    ) {}

    public function build(string $sourceDirectory, string $outputPath, ?string $privateKeyPath = null, ?string $passphrase = null): string
    {
        if (! is_dir($sourceDirectory)) {
            throw new PluginRuntimeException("Plugin source directory not found: {$sourceDirectory}");
        }

        $manifestPath = rtrim($sourceDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'plugin.json';

        if (! is_file($manifestPath)) {
            throw new PluginRuntimeException('Plugin source directory is missing plugin.json.');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);

        if (! is_array($manifest)) {
            throw new PluginRuntimeException('Plugin manifest is not valid JSON.');
        }

        $manifest = $this->manifestValidator->validate($manifest);

        if ($privateKeyPath) {
            $manifest = $this->signatureService->signManifest($manifest, $privateKeyPath, $passphrase);
        } else {
            $manifest['signature'] = ['algorithm' => 'none'];
        }

        $tempDirectory = config('plugins.paths.tmp').'/pack-'.uniqid();
        @mkdir($tempDirectory, 0777, true);

        $this->copyDirectory($sourceDirectory, $tempDirectory);
        file_put_contents(
            $tempDirectory.DIRECTORY_SEPARATOR.'plugin.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->zipDirectory($tempDirectory, $outputPath);

        return $outputPath;
    }

    private function copyDirectory(string $source, string $target): void
    {
        @mkdir($target, 0777, true);

        foreach (scandir($source) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }

            if ($entry === 'builds') {
                continue;
            }

            $sourcePath = $source.DIRECTORY_SEPARATOR.$entry;
            $targetPath = $target.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $targetPath);

                continue;
            }

            copy($sourcePath, $targetPath);
        }
    }

    private function zipDirectory(string $sourceDirectory, string $outputPath): void
    {
        @mkdir(dirname($outputPath), 0777, true);

        $zip = new ZipArchive;

        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new PluginRuntimeException("Unable to open output package: {$outputPath}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $realPath = $file->getRealPath();
            $relativePath = ltrim(str_replace($sourceDirectory, '', $realPath), DIRECTORY_SEPARATOR);
            $zip->addFile($realPath, $relativePath);
        }

        $zip->close();
    }
}
