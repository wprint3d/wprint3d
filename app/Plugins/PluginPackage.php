<?php

namespace App\Plugins;

class PluginPackage
{
    public function __construct(
        public array $manifest,
        public ?array $rawManifest,
        public string $archivePath,
        public string $archiveSha256,
        public string $sourceType,
        public string $trustLevel = 'unsigned',
        public array $warnings = [],
    ) {}
}
