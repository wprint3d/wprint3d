<?php

namespace App\Plugins;

use Illuminate\Http\Request;

class PluginRuntimeRequestPolicy
{
    public const BUCKET_PREVIEW = 'preview';

    public const BUCKET_SSE = 'sse';

    public const BUCKET_METADATA = 'metadata';

    public const BUCKET_UPLOAD = 'upload';

    public const BUCKET_MUTATION = 'mutation';

    public function bucket(Request $request): string
    {
        if (str_contains((string) $request->path(), 'runtime-artifacts/')) {
            return self::BUCKET_MUTATION;
        }

        $path = '/'.trim((string) ($request->route('runtimePath') ?? ''), '/');
        $method = strtoupper($request->method());
        $safeMethod = in_array($method, ['GET', 'HEAD'], true);

        if ($safeMethod && preg_match('#^/api/v2/slice-jobs/[^/]+/events$#', $path) === 1) {
            return self::BUCKET_SSE;
        }

        if ($safeMethod && $this->isPreviewOrAssetPath($path)) {
            return self::BUCKET_PREVIEW;
        }

        if ($safeMethod) {
            return self::BUCKET_METADATA;
        }

        if ($this->isUploadPath($path, strtolower((string) $request->header('content-type', '')))) {
            return self::BUCKET_UPLOAD;
        }

        return self::BUCKET_MUTATION;
    }

    public function limit(string $bucket): int
    {
        return max(1, (int) config("plugins.runtime.proxy_rate_limits.{$bucket}_per_minute", match ($bucket) {
            self::BUCKET_PREVIEW => 60_000,
            self::BUCKET_SSE => 120,
            self::BUCKET_METADATA => 3_000,
            self::BUCKET_UPLOAD => 120,
            default => 60,
        }));
    }

    public function identity(Request $request, string $bucket): string
    {
        $pluginId = (string) ($request->route('pluginId') ?? 'unknown');
        $principal = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

        return $principal.':'.$pluginId.':'.$bucket;
    }

    private function isPreviewOrAssetPath(string $path): bool
    {
        return preg_match('#^/api/v2/assets/[^/]+(?:/display\.glb)?$#', $path) === 1
            || preg_match('#^/api/v2/slice-jobs/[^/]+/artifacts(?:/.*)?$#', $path) === 1
            || preg_match('#^/api/v1/jobs/[^/]+/(?:layers(?:/[^/]+)?|preview-diagnostics|thumbnail|gcode)$#', $path) === 1;
    }

    private function isUploadPath(string $path, string $contentType): bool
    {
        if (str_starts_with($contentType, 'multipart/form-data')) {
            return true;
        }

        return preg_match('#^/api/v2/(?:assets/import|paint-textures|config/imports)(?:/|$)#', $path) === 1
            || preg_match('#^/api/v1/(?:uploads|config/imports)(?:/|$)#', $path) === 1;
    }
}
