<?php

namespace App\Plugins;

use App\Enums\DataType;
use App\Models\Configuration;
use App\Plugins\Exceptions\PluginRuntimeException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PluginRegistryClient
{
    private const CONFIGURATION_KEY = 'pluginTrustedRegistrySources';

    public function __construct(
        private ?string $indexUrl = null,
    ) {
        $this->indexUrl ??= config('plugins.registry.index_url');
    }

    public function listSources(): array
    {
        return array_values([
            $this->officialSource(),
            ...$this->trustedSources(),
        ]);
    }

    public function saveSources(array $sources): array
    {
        $normalizedSources = collect($sources)
            ->filter(fn ($source) => is_array($source))
            ->values()
            ->all();

        $usedIds = ['official'];

        $payload = collect($normalizedSources)
            ->map(function (array $source) use (&$usedIds) {
                $name = trim((string) ($source['name'] ?? ''));
                $indexUrl = trim((string) ($source['indexUrl'] ?? ''));
                $websiteUrl = trim((string) ($source['websiteUrl'] ?? ''));

                if ($name === '' || $indexUrl === '') {
                    return null;
                }

                $id = $this->uniqueSourceId($name, $usedIds);

                return [
                    'id' => $id,
                    'name' => $name,
                    'indexUrl' => $indexUrl,
                    'websiteUrl' => $websiteUrl !== '' ? $websiteUrl : Str::beforeLast($indexUrl, '/'),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $configuration = Configuration::firstOrNew(['key' => self::CONFIGURATION_KEY]);
        $configuration->forceFill([
            'key' => self::CONFIGURATION_KEY,
            'value' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'default' => '[]',
            'hint' => 'Trusted plugin registry sources',
            'type' => DataType::STRING,
            'section' => 'Plugins',
            'description' => 'Trusted third-party plugin registries for the marketplace.',
            'visible' => false,
            'writeable' => true,
        ]);
        $configuration->save();

        return $this->listSources();
    }

    public function listPackages(?array $sources = null): array
    {
        return collect($sources ?? $this->listSources())
            ->flatMap(function (array $source) {
                try {
                    return $this->fetchPackagesFromSource($source);
                } catch (\Throwable $exception) {
                    if ($source['official'] ?? false) {
                        throw $exception;
                    }

                    return [];
                }
            })
            ->values()
            ->all();
    }

    public function getPackage(string $pluginId, ?string $version = null, ?string $sourceId = null): array
    {
        $sources = collect($this->listSources())
            ->when($sourceId !== null, fn ($collection) => $collection->where('id', $sourceId))
            ->values();

        if ($sourceId !== null && $sources->isEmpty()) {
            throw new PluginRuntimeException("Plugin registry source {$sourceId} is not configured.");
        }

        foreach ($sources as $source) {
            try {
                $packages = $this->fetchPackagesFromSource($source);
            } catch (\Throwable $exception) {
                if ($source['official'] ?? false) {
                    throw $exception;
                }

                continue;
            }

            foreach ($packages as $package) {
                if (($package['id'] ?? null) !== $pluginId) {
                    continue;
                }

                if ($version === null) {
                    return $package;
                }

                foreach (($package['versions'] ?? []) as $candidate) {
                    if (($candidate['version'] ?? null) === $version) {
                        return array_merge($package, $candidate);
                    }
                }
            }
        }

        throw new PluginRuntimeException("Plugin {$pluginId} was not found in the registry index.");
    }

    public function resolvePackageUrl(array $package): string
    {
        if (! empty($package['packageUrl'])) {
            return $package['packageUrl'];
        }

        if (! empty($package['asset'])) {
            $websiteUrl = $package['registrySource']['websiteUrl'] ?? config('plugins.registry.website_url');

            return Str::finish($websiteUrl, '/').ltrim($package['asset'], '/');
        }

        throw new PluginRuntimeException('Registry package does not declare a package URL.');
    }

    private function fetchPackagesFromSource(array $source): array
    {
        if (empty($source['indexUrl'])) {
            return [];
        }

        $response = Http::timeout(config('plugins.runtime.bridge_timeout_secs', 5))
            ->get($source['indexUrl']);

        $response->throw();

        $payload = $response->json();
        $plugins = isset($payload['plugins']) && is_array($payload['plugins'])
            ? array_values($payload['plugins'])
            : (is_array($payload) ? array_values($payload) : []);

        return collect($plugins)
            ->filter(fn ($plugin) => is_array($plugin))
            ->map(fn (array $plugin) => array_merge($plugin, [
                'registrySource' => Arr::only($source, ['id', 'name', 'indexUrl', 'websiteUrl', 'official', 'trustLevel']),
            ]))
            ->values()
            ->all();
    }

    private function officialSource(): array
    {
        return [
            'id' => 'official',
            'name' => 'Official registry',
            'indexUrl' => config('plugins.registry.index_url'),
            'websiteUrl' => config('plugins.registry.website_url'),
            'official' => true,
            'trustLevel' => 'official',
        ];
    }

    private function trustedSources(): array
    {
        $raw = Configuration::get(self::CONFIGURATION_KEY, '[]');

        if (is_array($raw)) {
            $rawSources = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
            $rawSources = is_array($decoded) ? $decoded : [];
        }

        $usedIds = ['official'];

        return collect($rawSources)
            ->filter(fn ($source) => is_array($source))
            ->map(function (array $source) use (&$usedIds) {
                $name = trim((string) ($source['name'] ?? ''));
                $indexUrl = trim((string) ($source['indexUrl'] ?? ''));

                if ($name === '' || $indexUrl === '') {
                    return null;
                }

                $id = trim((string) ($source['id'] ?? ''));

                if ($id === '' || in_array($id, $usedIds, true)) {
                    $id = $this->uniqueSourceId($name, $usedIds);
                } else {
                    $usedIds[] = $id;
                }

                return [
                    'id' => $id,
                    'name' => $name,
                    'indexUrl' => $indexUrl,
                    'websiteUrl' => trim((string) ($source['websiteUrl'] ?? '')) ?: Str::beforeLast($indexUrl, '/'),
                    'official' => false,
                    'trustLevel' => 'trusted_registry',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function uniqueSourceId(string $name, array &$usedIds): string
    {
        $baseId = Str::slug($name);
        $baseId = $baseId !== '' ? $baseId : 'registry';
        $candidate = $baseId;
        $suffix = 2;

        while (in_array($candidate, $usedIds, true)) {
            $candidate = "{$baseId}-{$suffix}";
            $suffix++;
        }

        $usedIds[] = $candidate;

        return $candidate;
    }
}
