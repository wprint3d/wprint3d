<?php

namespace App\Console\Commands;

use App\Plugins\Builtins\BuiltinCompatibilityValidator;
use App\Plugins\Builtins\BuiltinPluginDescriptor;
use App\Plugins\Builtins\BuiltinPluginRepository;
use App\Plugins\Contracts\PluginManager;
use App\Plugins\PluginArchiveService;
use Illuminate\Console\Command;

class PluginInstallBuiltins extends Command
{
    protected $signature = 'plugin:install-builtins {--enable : Enable built-ins marked for automatic activation}';

    protected $description = 'Install built-in W3DP packages shipped with the WPrint 3D image.';

    public function handle(
        PluginManager $pluginManager,
        BuiltinPluginRepository $repository,
        PluginArchiveService $archiveService,
        BuiltinCompatibilityValidator $compatibilityValidator,
    ): int {
        $failed = 0;

        try {
            $descriptors = $repository->descriptors();
        } catch (\Throwable $exception) {
            $this->error('Unable to read built-in inventory: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($descriptors as $descriptor) {
            try {
                $this->installDescriptor($pluginManager, $repository, $archiveService, $compatibilityValidator, $descriptor);
            } catch (\Throwable $exception) {
                $message = "Unable to install built-in {$descriptor->id}: {$exception->getMessage()}";
                if ($descriptor->required) {
                    $this->error($message);
                    $failed++;
                } else {
                    $this->warn($message);
                }
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function installDescriptor(
        PluginManager $pluginManager,
        BuiltinPluginRepository $repository,
        PluginArchiveService $archiveService,
        BuiltinCompatibilityValidator $compatibilityValidator,
        BuiltinPluginDescriptor $descriptor,
    ): void {
        $feature = $descriptor->feature;
        if ($feature !== null && $feature !== '' && ! (bool) config("plugins.rollout.{$feature}", true)) {
            $this->line("Skipped disabled built-in {$descriptor->id}.");

            return;
        }

        $path = $repository->archivePath($descriptor);
        $package = $archiveService->inspect($path, 'builtin');
        if (($package->manifest['id'] ?? null) !== $descriptor->id || ($package->manifest['version'] ?? null) !== $descriptor->version) {
            throw new \RuntimeException("Built-in archive does not match {$descriptor->id} {$descriptor->version}.");
        }
        if (($package->trustLevel ?? 'unsigned') !== 'signed') {
            throw new \RuntimeException('Built-in archive is not trusted by this WPrint instance.');
        }
        if ($descriptor->compatibility !== []) {
            $compatibilityValidator->validate(
                $descriptor->compatibility,
                $descriptor->id,
                $descriptor->version,
                $package->archiveSha256,
                $package->manifest,
                basename($path),
            );
        }

        $plugin = $pluginManager->findModel($descriptor->id);
        $wasEnabled = $plugin?->enabled === true;
        $installedVersion = (string) ($plugin?->current_version ?? '');
        if ($plugin && $installedVersion !== '' && version_compare($installedVersion, $descriptor->version, '>=')) {
            if ($installedVersion !== $descriptor->version) {
                $this->line("Kept newer installed built-in {$descriptor->id} {$installedVersion}.");
            }

            return;
        }

        if (! $plugin) {
            $installed = $pluginManager->installFromArchive($path, 'builtin', [
                'builtinId' => $descriptor->id,
                'bundledVersion' => $descriptor->version,
                'archiveSha256' => $package->archiveSha256,
                'path' => $path,
            ]);
            $this->info("Installed built-in {$installed['id']}.");
            $plugin = $pluginManager->findModel($descriptor->id);
        } elseif ($installedVersion !== $descriptor->version) {
            $pluginManager->installFromArchive($path, 'builtin', [
                'builtinId' => $descriptor->id,
                'bundledVersion' => $descriptor->version,
                'archiveSha256' => $package->archiveSha256,
                'path' => $path,
            ]);
            $this->info("Updated built-in {$descriptor->id} to {$descriptor->version}.");
            $plugin = $pluginManager->findModel($descriptor->id);
            if ($wasEnabled && $plugin && ! $plugin->enabled) {
                $pluginManager->enable($descriptor->id);
            }
        }

        $autoEnable = $descriptor->defaultEnabled;
        if ($descriptor->id === 'cura-web-ui') {
            $autoEnable = $autoEnable || (bool) config('plugins.rollout.builtin_cura_auto_enable', false);
        }

        if (($this->option('enable') || $autoEnable) && $plugin && ! $plugin->enabled) {
            $pluginManager->enable($descriptor->id);
        }
    }
}
