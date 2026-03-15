<?php

namespace App\Http\Controllers;

use App\Plugins\Contracts\PluginManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PluginController extends Controller
{
    public function __construct(
        private PluginManager $pluginManager,
    ) {}

    public function sdk(): array
    {
        return $this->pluginManager->sdkMetadata();
    }

    public function index(): array
    {
        return $this->pluginManager->listInstalled();
    }

    public function show(string $pluginId): array
    {
        return $this->pluginManager->get($pluginId);
    }

    public function settings(string $pluginId): array
    {
        return $this->pluginManager->getSettings($pluginId);
    }

    public function updateSettings(string $pluginId, Request $request): array
    {
        $validated = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        return $this->pluginManager->updateSettings($pluginId, $validated['settings']);
    }

    public function state(string $pluginId): array
    {
        return $this->pluginManager->getState($pluginId);
    }

    public function logs(string $pluginId): array
    {
        return $this->pluginManager->getLogs($pluginId);
    }

    public function registry(): array
    {
        return $this->pluginManager->listRegistry();
    }

    public function registrySources(): array
    {
        return $this->pluginManager->listRegistrySources();
    }

    public function updateRegistrySources(Request $request): array
    {
        $validated = $request->validate([
            'sources' => ['required', 'array'],
            'sources.*.name' => ['required', 'string'],
            'sources.*.indexUrl' => ['required', 'url'],
            'sources.*.websiteUrl' => ['nullable', 'url'],
        ]);

        return $this->pluginManager->saveRegistrySources($validated['sources']);
    }

    public function development(): array
    {
        $enabled = $this->developmentModeEnabled();
        $mountPaths = $this->developmentMountPaths($enabled);
        $mountPath = $mountPaths[0] ?? null;
        $plugins = $enabled ? $this->pluginManager->listDevelopmentPlugins() : [];
        $available = $enabled && ($mountPath !== null || count($plugins) > 0);

        return [
            'enabled' => $enabled,
            'available' => $available,
            'mountPath' => $mountPath,
            'mountPaths' => $mountPaths,
            'configuredMountPath' => (string) config('plugins.development.mount_path'),
            'configuredMountPaths' => $this->configuredDevelopmentMountPaths(),
            'plugins' => $plugins,
        ];
    }

    public function install(Request $request): array
    {
        if ($request->hasFile('package')) {
            $file = $request->file('package');

            if (! $file || ! $file->isValid()) {
                throw ValidationException::withMessages(['package' => 'The uploaded package is invalid.']);
            }

            return $this->pluginManager->installFromArchive($file->getRealPath(), 'local_upload', [
                'original_name' => $file->getClientOriginalName(),
            ]);
        }

        if ($request->filled('url')) {
            return $this->pluginManager->installFromUrl($request->string('url')->toString());
        }

        if ($request->filled('unpackedPath')) {
            if (! $this->developmentModeEnabled()) {
                throw new AuthorizationException('Unpacked plugin installs are only available in the development environment.');
            }

            return $this->pluginManager->installFromDevelopmentPath($request->string('unpackedPath')->toString());
        }

        if ($request->filled('pluginId')) {
            return $this->pluginManager->installFromRegistry(
                pluginId: $request->string('pluginId')->toString(),
                version: $request->filled('version') ? $request->string('version')->toString() : null,
                sourceId: $request->filled('sourceId') ? $request->string('sourceId')->toString() : null,
            );
        }

        throw ValidationException::withMessages([
            'package' => 'Provide a package upload, URL, unpackedPath, or registry pluginId.',
        ]);
    }

    public function enable(string $pluginId): array
    {
        return $this->pluginManager->enable($pluginId);
    }

    public function disable(string $pluginId): array
    {
        return $this->pluginManager->disable($pluginId);
    }

    public function delete(string $pluginId): Response
    {
        $this->pluginManager->uninstall($pluginId);

        return response('', Response::HTTP_NO_CONTENT);
    }

    public function update(string $pluginId): array
    {
        return $this->pluginManager->update($pluginId);
    }

    public function ui(Request $request): array
    {
        return $this->pluginManager->listUiExtensions(
            $request->filled('surface') ? $request->string('surface')->toString() : null
        );
    }

    public function invokeAction(string $pluginId, string $actionId, Request $request): array
    {
        return $this->pluginManager->invokeAction(
            pluginId: $pluginId,
            actionId: $actionId,
            payload: $request->input('payload', []),
            context: [
                'userId' => optional($request->user())->_id,
                'printerId' => $request->input('printerId'),
            ],
        );
    }

    public function asset(string $pluginId, string $assetPath): Response
    {
        $asset = $this->pluginManager->resolveAsset($pluginId, $assetPath);

        return response(
            file_get_contents($asset['path']),
            Response::HTTP_OK,
            [
                'Content-Type' => $asset['mimeType'],
                'Cache-Control' => 'private, max-age=60',
            ],
        );
    }

    public function octoPrintCompatScript(): Response
    {
        return response(
            file_get_contents(base_path('resources/plugin-sdk/octoprint-compat.js')),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/javascript',
                'Cache-Control' => 'private, max-age=60',
            ],
        );
    }

    public function doctor(): array
    {
        return $this->pluginManager->doctor();
    }

    public function safeMode(): array
    {
        return [
            'disabledCount' => $this->pluginManager->safeModeDisableAll(),
        ];
    }

    private function developmentMountPath(bool $includeImplicitRoots = true): ?string
    {
        return $this->developmentMountPaths($includeImplicitRoots)[0] ?? null;
    }

    private function developmentModeEnabled(): bool
    {
        if ($this->developmentModeExplicitlyEnabled()) {
            return true;
        }

        return $this->developmentMountPath(false) !== null;
    }

    private function developmentModeExplicitlyEnabled(): bool
    {
        if ((bool) config('plugins.development.enabled', false)) {
            return true;
        }

        $envValue = env('DEVELOPER_MODE');

        if ($envValue !== null && filter_var($envValue, FILTER_VALIDATE_BOOL)) {
            return true;
        }

        $serverValue = $_SERVER['DEVELOPER_MODE'] ?? $_ENV['DEVELOPER_MODE'] ?? getenv('DEVELOPER_MODE');

        return filter_var($serverValue, FILTER_VALIDATE_BOOL);
    }

    private function developmentMountPaths(bool $includeImplicitRoots = true): array
    {
        $paths = [];

        if ($includeImplicitRoots) {
            $localPluginsPath = $this->resolveDevelopmentMountCandidate(base_path('plugins'));

            if ($localPluginsPath !== null) {
                $paths[] = $localPluginsPath;
            }
        }

        foreach ($this->configuredDevelopmentMountPaths() as $candidate) {
            $resolvedPath = $this->resolveDevelopmentMountCandidate($candidate);

            if ($resolvedPath !== null) {
                $paths[] = $resolvedPath;
            }
        }

        return array_values(array_unique($paths));
    }

    private function configuredDevelopmentMountPaths(): array
    {
        $configured = config('plugins.development.mount_paths', []);

        if (! is_array($configured)) {
            $configured = array_map('trim', explode(',', (string) $configured));
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($path) => rtrim((string) $path, DIRECTORY_SEPARATOR),
            [
                (string) config('plugins.development.mount_path', ''),
                ...$configured,
            ]
        ))));
    }

    private function resolveDevelopmentMountCandidate(string $candidate): ?string
    {
        $candidate = rtrim($candidate, DIRECTORY_SEPARATOR);

        if ($candidate === '') {
            return null;
        }

        $resolvedPath = realpath($candidate);

        if ($resolvedPath && is_dir($resolvedPath)) {
            return rtrim($resolvedPath, DIRECTORY_SEPARATOR);
        }

        if (is_dir($candidate)) {
            return $candidate;
        }

        return null;
    }
}
