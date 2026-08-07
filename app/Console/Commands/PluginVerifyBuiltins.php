<?php

namespace App\Console\Commands;

use App\Plugins\Builtins\BuiltinCompatibilityValidator;
use App\Plugins\Builtins\BuiltinPluginRepository;
use App\Plugins\PluginArchiveService;
use Illuminate\Console\Command;

class PluginVerifyBuiltins extends Command
{
    protected $signature = 'plugin:verify-builtins';

    protected $description = 'Verify every release-staged built-in plugin archive and its trust status.';

    public function handle(
        BuiltinPluginRepository $repository,
        PluginArchiveService $archiveService,
        BuiltinCompatibilityValidator $compatibilityValidator,
    ): int {
        try {
            foreach ($repository->descriptors() as $descriptor) {
                $archivePath = $repository->archivePath($descriptor);
                $package = $archiveService->inspect($archivePath, 'builtin_verify');
                $archiveService->verifyIntegrity($archivePath, $package->manifest);
                if (($package->manifest['id'] ?? null) !== $descriptor->id
                    || ($package->manifest['version'] ?? null) !== $descriptor->version
                    || $package->trustLevel !== 'signed') {
                    throw new \RuntimeException("Built-in {$descriptor->id} does not match its trusted inventory descriptor.");
                }
                if ($descriptor->compatibility !== []) {
                    $compatibilityValidator->validate(
                        $descriptor->compatibility,
                        $descriptor->id,
                        $descriptor->version,
                        $package->archiveSha256,
                        $package->manifest,
                        basename($repository->archivePath($descriptor)),
                    );
                }
                $this->info("Verified built-in {$descriptor->id} {$descriptor->version}.");
            }
        } catch (\Throwable $exception) {
            $this->error('Built-in verification failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
