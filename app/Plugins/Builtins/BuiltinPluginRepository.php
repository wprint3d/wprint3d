<?php

namespace App\Plugins\Builtins;

use App\Plugins\Exceptions\PluginRuntimeException;
use Illuminate\Support\Facades\File;

final class BuiltinPluginRepository
{
    public function descriptors(): array
    {
        $inventoryPath = (string) config('plugins.builtins.inventory', '');
        if ($inventoryPath !== '' && is_file($inventoryPath)) {
            return $this->readInventory($inventoryPath);
        }

        return array_values(array_map(
            fn (array $entry) => BuiltinPluginDescriptor::fromArray($entry),
            config('plugins.builtins.entries', [])
        ));
    }

    public function archivePath(BuiltinPluginDescriptor $descriptor): string
    {
        if (! $descriptor->fromInventory) {
            $path = $descriptor->archive;
            if (! is_file($path)) {
                throw new PluginRuntimeException("Built-in archive is missing: {$path}");
            }

            return $path;
        }

        $inventoryPath = (string) config('plugins.builtins.inventory', '');
        $inventoryDirectory = realpath(dirname($inventoryPath));
        if ($inventoryDirectory === false) {
            throw new PluginRuntimeException('Built-in inventory directory is unavailable.');
        }

        $candidate = realpath($inventoryDirectory.DIRECTORY_SEPARATOR.$descriptor->archive);
        if ($candidate === false || ! is_file($candidate) || ! str_starts_with($candidate, $inventoryDirectory.DIRECTORY_SEPARATOR)) {
            throw new PluginRuntimeException("Built-in archive is missing: {$descriptor->archive}");
        }

        $actualSha256 = hash_file('sha256', $candidate);
        if (! hash_equals((string) $descriptor->sha256, (string) $actualSha256)) {
            throw new PluginRuntimeException("Built-in archive checksum mismatch for {$descriptor->id}.");
        }

        return $candidate;
    }

    private function readInventory(string $path): array
    {
        try {
            $inventory = json_decode(File::get($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new PluginRuntimeException('Built-in inventory could not be read: '.$exception->getMessage(), previous: $exception);
        }

        if (($inventory['schemaVersion'] ?? null) !== 1 || ! is_array($inventory['plugins'] ?? null)) {
            throw new PluginRuntimeException('Built-in inventory has an unsupported schema.');
        }

        $seen = [];
        $descriptors = [];
        foreach ($inventory['plugins'] as $entry) {
            if (! is_array($entry)) {
                throw new PluginRuntimeException('Built-in inventory contains a malformed descriptor.');
            }

            $descriptor = BuiltinPluginDescriptor::fromArray($entry, true);
            if (isset($seen[$descriptor->id])) {
                throw new PluginRuntimeException("Built-in inventory contains duplicate plugin {$descriptor->id}.");
            }
            $seen[$descriptor->id] = true;
            $descriptors[] = $descriptor;
        }

        return $descriptors;
    }
}
