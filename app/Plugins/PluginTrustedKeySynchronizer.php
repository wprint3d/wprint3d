<?php

namespace App\Plugins;

use App\Plugins\Exceptions\PluginRuntimeException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PluginTrustedKeySynchronizer
{
    public function __construct(
        private ?PluginRegistryClient $registryClient = null,
        private ?PluginSignatureService $signatureService = null,
        private ?string $storageRoot = null,
        private ?array $configuredKeyPaths = null,
        private ?string $defaultKeysIndexPath = null,
        private ?int $timeoutSecs = null,
    ) {
        $this->registryClient ??= function_exists('app') && app()->bound(PluginRegistryClient::class)
            ? app(PluginRegistryClient::class)
            : null;
        $this->signatureService ??= function_exists('app') && app()->bound(PluginSignatureService::class)
            ? app(PluginSignatureService::class)
            : new PluginSignatureService;
        $this->storageRoot ??= config('plugins.signature.synced_trusted_keys_path', storage_path('app/plugins/trusted-keys'));
        $this->configuredKeyPaths ??= config('plugins.signature.trusted_public_keys', []);
        $this->defaultKeysIndexPath ??= config('plugins.registry.trusted_keys_index_path', 'signers/index.json');
        $this->timeoutSecs ??= (int) config('plugins.runtime.bridge_timeout_secs', 5);
    }

    public function allTrustedKeyPaths(): array
    {
        return array_values(array_unique(array_filter([
            ...$this->configuredKeyPaths(),
            ...$this->syncedKeyPaths(),
        ])));
    }

    public function sync(?array $sources = null): array
    {
        $sources ??= $this->registryClient?->listSources()
            ?? throw new PluginRuntimeException('Plugin registry client is required to sync trusted keys.');

        File::ensureDirectoryExists($this->storageRoot);

        $activeSourceIds = [];
        $sourcesSynced = 0;
        $keysSynced = 0;
        $failures = [];

        foreach ($sources as $source) {
            if (! is_array($source) || empty($source['id']) || empty($source['indexUrl'])) {
                continue;
            }

            $activeSourceIds[] = (string) $source['id'];

            try {
                $keysSynced += $this->syncSource($source);
                $sourcesSynced++;
            } catch (\Throwable $exception) {
                $failures[] = [
                    'sourceId' => (string) $source['id'],
                    'sourceName' => (string) ($source['name'] ?? $source['id']),
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $this->deleteRemovedSourceDirectories($activeSourceIds);

        return [
            'sourcesSynced' => $sourcesSynced,
            'keysSynced' => $keysSynced,
            'sourcesFailed' => count($failures),
            'failures' => $failures,
        ];
    }

    private function syncSource(array $source): int
    {
        $keysIndexUrl = $this->keysIndexUrlForSource($source);
        $response = Http::timeout($this->timeoutSecs)->get($keysIndexUrl);
        $response->throw();

        $payload = $response->json();
        $entries = $this->normalizeKeyEntries($payload);
        $sourceId = (string) $source['id'];
        $sourceDirectory = $this->sourceDirectory($sourceId);
        $temporaryDirectory = $sourceDirectory.'-tmp-'.uniqid();

        File::deleteDirectory($temporaryDirectory);
        File::ensureDirectoryExists($temporaryDirectory);

        foreach ($entries as $entry) {
            $publicKeyPem = $this->downloadAndNormalizePublicKey($entry);
            $filePath = $temporaryDirectory.DIRECTORY_SEPARATOR.$this->keyFilename($entry).'.pem';

            File::put($filePath, $publicKeyPem);
        }

        File::deleteDirectory($sourceDirectory);
        File::moveDirectory($temporaryDirectory, $sourceDirectory, true);

        return count($entries);
    }

    private function configuredKeyPaths(): array
    {
        return array_values(array_filter(array_map('strval', $this->configuredKeyPaths ?? [])));
    }

    private function syncedKeyPaths(): array
    {
        if (! is_dir($this->storageRoot)) {
            return [];
        }

        return collect(File::allFiles($this->storageRoot))
            ->map(fn ($file) => $file->getPathname())
            ->filter(fn ($path) => Str::endsWith($path, '.pem'))
            ->values()
            ->all();
    }

    private function deleteRemovedSourceDirectories(array $activeSourceIds): void
    {
        if (! is_dir($this->storageRoot)) {
            return;
        }

        $activeLookup = array_flip($activeSourceIds);

        foreach (File::directories($this->storageRoot) as $directory) {
            $directoryName = basename($directory);

            if (! array_key_exists($directoryName, $activeLookup)) {
                File::deleteDirectory($directory);
            }
        }
    }

    private function keysIndexUrlForSource(array $source): string
    {
        $trustedKeysUrl = trim((string) ($source['trustedKeysUrl'] ?? ''));

        if ($trustedKeysUrl !== '') {
            return $trustedKeysUrl;
        }

        return Str::finish(Str::beforeLast((string) $source['indexUrl'], '/'), '/').ltrim($this->defaultKeysIndexPath, '/');
    }

    private function normalizeKeyEntries(mixed $payload): array
    {
        $entries = isset($payload['keys']) && is_array($payload['keys'])
            ? $payload['keys']
            : (is_array($payload) ? $payload : []);

        return collect($entries)
            ->map(function ($entry) {
                if (is_string($entry)) {
                    $entry = ['url' => $entry];
                }

                if (! is_array($entry)) {
                    return null;
                }

                $url = trim((string) ($entry['url'] ?? ''));

                if ($url === '') {
                    return null;
                }

                $id = trim((string) ($entry['id'] ?? pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_FILENAME)));

                if ($id === '') {
                    return null;
                }

                return [
                    'id' => $id,
                    'url' => $url,
                    'publicKeySha256' => trim((string) ($entry['publicKeySha256'] ?? '')) ?: null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function downloadAndNormalizePublicKey(array $entry): string
    {
        $response = Http::timeout($this->timeoutSecs)->get($entry['url']);
        $response->throw();

        $publicKey = openssl_pkey_get_public((string) $response->body());

        if ($publicKey === false) {
            throw new PluginRuntimeException("Registry signer key {$entry['id']} is not a valid public key.");
        }

        $details = openssl_pkey_get_details($publicKey);
        $normalizedPem = trim((string) ($details['key'] ?? ''))."\n";

        if ($normalizedPem === "\n") {
            throw new PluginRuntimeException("Registry signer key {$entry['id']} could not be normalized.");
        }

        $expectedSha256 = $entry['publicKeySha256'] ?? null;

        if ($expectedSha256 && $this->signatureService->publicKeySha256($normalizedPem) !== $expectedSha256) {
            throw new PluginRuntimeException("Registry signer key {$entry['id']} fingerprint did not match its declared SHA-256.");
        }

        return $normalizedPem;
    }

    private function sourceDirectory(string $sourceId): string
    {
        return rtrim($this->storageRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$sourceId;
    }

    private function keyFilename(array $entry): string
    {
        $candidate = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $entry['id']) ?: 'signer-key';

        return trim($candidate, '.-') !== '' ? trim($candidate, '.-') : 'signer-key';
    }
}
