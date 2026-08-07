<?php

namespace App\Plugins\Builtins;

use InvalidArgumentException;

final class BuiltinPluginDescriptor
{
    public function __construct(
        public readonly string $id,
        public readonly string $version,
        public readonly string $archive,
        public readonly ?string $sha256 = null,
        public readonly bool $defaultEnabled = false,
        public readonly bool $required = false,
        public readonly ?string $feature = null,
        public readonly array $compatibility = [],
        public readonly bool $fromInventory = false,
    ) {}

    public static function fromArray(array $descriptor, bool $fromInventory = false): self
    {
        $id = trim((string) ($descriptor['id'] ?? ''));
        $version = trim((string) ($descriptor['version'] ?? ''));
        $archive = (string) ($descriptor['archive'] ?? '');
        $sha256 = isset($descriptor['sha256']) ? strtolower(trim((string) $descriptor['sha256'])) : null;

        if ($id === '' || ! preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id)) {
            throw new InvalidArgumentException('Built-in plugin id is invalid.');
        }
        if ($version === '' || ! preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
            throw new InvalidArgumentException("Built-in plugin {$id} has an invalid version.");
        }
        if ($archive === '' || str_contains($archive, "\0") || ($fromInventory && str_starts_with($archive, '/')) || str_contains($archive, '\\')) {
            throw new InvalidArgumentException("Built-in plugin {$id} has an invalid archive path.");
        }
        $segments = explode('/', $archive);
        if (in_array('..', $segments, true) || ! str_ends_with(strtolower($archive), '.w3dp')) {
            throw new InvalidArgumentException("Built-in plugin {$id} has an invalid archive path.");
        }
        if ($fromInventory && (! is_string($sha256) || ! preg_match('/^[a-f0-9]{64}$/', $sha256))) {
            throw new InvalidArgumentException("Built-in plugin {$id} requires a SHA-256.");
        }
        if ($sha256 !== null && ! preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new InvalidArgumentException("Built-in plugin {$id} has an invalid SHA-256.");
        }

        return new self(
            id: $id,
            version: $version,
            archive: $archive,
            sha256: $sha256,
            defaultEnabled: (bool) ($descriptor['defaultEnabled'] ?? false),
            required: (bool) ($descriptor['required'] ?? false),
            feature: isset($descriptor['feature']) ? trim((string) $descriptor['feature']) : null,
            compatibility: is_array($descriptor['compatibility'] ?? null) ? $descriptor['compatibility'] : [],
            fromInventory: $fromInventory,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'version' => $this->version,
            'archive' => $this->archive,
            'sha256' => $this->sha256,
            'defaultEnabled' => $this->defaultEnabled,
            'required' => $this->required,
            'feature' => $this->feature,
            'compatibility' => $this->compatibility !== [] ? $this->compatibility : null,
        ], static fn ($value) => $value !== null);
    }
}
