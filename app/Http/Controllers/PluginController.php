<?php

namespace App\Http\Controllers;

use App\Plugins\Contracts\PluginManager;
use App\Plugins\Exceptions\PluginRuntimeException;
use App\Plugins\PluginDependencyService;
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
        return array_map(fn (array $plugin) => $this->browserSafePayload($plugin), $this->pluginManager->listInstalled());
    }

    public function show(string $pluginId): array
    {
        return $this->browserSafePayload($this->pluginManager->get($pluginId));
    }

    public function preferences(): array
    {
        return $this->pluginManager->getPluginPreferences();
    }

    public function updatePreferences(Request $request): array
    {
        $validated = $request->validate([
            'automaticUpdatesEnabled' => ['required', 'boolean'],
        ]);

        return $this->pluginManager->updatePluginPreferences($validated);
    }

    public function setAutomaticUpdates(string $pluginId, Request $request): array
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        return $this->pluginManager->setPluginAutomaticUpdates($pluginId, $validated['enabled']);
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
                throw ValidationException::withMessages(['package' => __('server.plugins.invalid_upload')]);
            }

            return $this->browserSafePayload($this->pluginManager->installFromArchive($file->getRealPath(), 'local_upload', [
                'original_name' => $file->getClientOriginalName(),
            ]));
        }

        if ($request->filled('url')) {
            return $this->browserSafePayload($this->pluginManager->installFromUrl($request->string('url')->toString()));
        }

        if ($request->filled('unpackedPath')) {
            if (! $this->developmentModeEnabled()) {
                throw new AuthorizationException(__('server.plugins.unpacked_install_development_only'));
            }

            return $this->browserSafePayload($this->pluginManager->installFromDevelopmentPath($request->string('unpackedPath')->toString()));
        }

        if ($request->filled('pluginId')) {
            return $this->browserSafePayload($this->pluginManager->installFromRegistry(
                pluginId: $request->string('pluginId')->toString(),
                version: $request->filled('version') ? $request->string('version')->toString() : null,
                sourceId: $request->filled('sourceId') ? $request->string('sourceId')->toString() : null,
            ));
        }

        throw ValidationException::withMessages([
            'package' => __('server.plugins.install_source_required'),
        ]);
    }

    public function enable(string $pluginId, Request $request): array
    {
        $validated = $request->validate([
            'overrideRequirements' => ['sometimes', 'boolean'],
        ]);

        return $this->browserSafePayload($this->pluginManager->enable(
            $pluginId,
            (bool) ($validated['overrideRequirements'] ?? false),
            optional($request->user())->_id !== null
                ? (string) optional($request->user())->_id
                : null,
        ));
    }

    public function disable(string $pluginId): array
    {
        return $this->browserSafePayload($this->pluginManager->disable($pluginId));
    }

    public function delete(string $pluginId): Response
    {
        $this->pluginManager->uninstall($pluginId);

        return response('', Response::HTTP_NO_CONTENT);
    }

    public function deleteRuntimeStorage(string $pluginId, Request $request, PluginDependencyService $dependencyService): array
    {
        $validated = $request->validate([
            'expectedVolume' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
        ]);
        $plugin = $this->pluginManager->findModel($pluginId);

        if (! $plugin) {
            abort(Response::HTTP_NOT_FOUND, 'Plugin is not installed.');
        }

        if ($plugin->enabled) {
            abort(Response::HTTP_CONFLICT, 'Disable the plugin before deleting retained runtime storage.');
        }

        $payload = [
            'id' => $plugin->plugin_id,
            'manifest' => is_array($plugin->manifest) ? $plugin->manifest : [],
        ];
        $expectedVolume = $validated['expectedVolume'];
        if (! in_array($expectedVolume, $dependencyService->persistentStorageNames($payload), true)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'The requested volume is not managed by this WPrint plugin.');
        }

        $deleted = $dependencyService->deletePersistentStorage($payload);

        return [
            'pluginId' => $plugin->plugin_id,
            'expectedVolume' => $expectedVolume,
            'deletedVolumes' => $deleted,
        ];
    }

    public function update(string $pluginId): array
    {
        return $this->browserSafePayload($this->pluginManager->update($pluginId));
    }

    public function checkUpdates(): array
    {
        return $this->browserSafePayload($this->pluginManager->checkForPluginUpdates(false));
    }

    public function updateAll(): array
    {
        return $this->browserSafePayload($this->pluginManager->updateAllPlugins(false));
    }

    public function disableAll(): array
    {
        return $this->browserSafePayload($this->pluginManager->disableAll());
    }

    public function enableAll(): array
    {
        return $this->browserSafePayload($this->pluginManager->enableAll());
    }

    public function ui(Request $request): array
    {
        return $this->pluginManager->listUiExtensions(
            $request->filled('surface') ? $request->string('surface')->toString() : null
        );
    }

    public function hostContext(string $pluginId, Request $request): array
    {
        $user = $request->user();

        return $this->pluginManager->hostContext(
            pluginId: $pluginId,
            user: $user instanceof \App\Models\User ? $user : null,
            locale: $request->getPreferredLanguage(['en', 'es-AR', 'es', 'fr', 'pt', 'it', 'de']) ?: null,
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
        try {
            $asset = $this->pluginManager->resolveAsset($pluginId, $assetPath);
        } catch (PluginRuntimeException $exception) {
            abort(Response::HTTP_NOT_FOUND, $exception->getMessage());
        }

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

    /**
     * Manager payloads also serve internal lifecycle calls, so they contain
     * runtime state that must never cross the HTTP/browser boundary.
     */
    private function browserSafePayload(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $redactedKeys = [
            'authTokenCiphertext',
            'baseUrl',
            'socketPath',
            'runtimePath',
            'runtime_path',
            'storagePath',
            'storage_path',
            'dependency_state',
            'networkName',
            'containerName',
            'candidateContainerName',
            'candidateNetworkAlias',
            'canonicalContainerName',
            'canonicalNetworkAlias',
            'configFingerprint',
        ];

        foreach ($payload as $key => $value) {
            if (in_array((string) $key, $redactedKeys, true)) {
                unset($payload[$key]);

                continue;
            }

            $payload[$key] = is_array($value) ? $this->browserSafePayload($value) : $value;
        }

        return $payload;
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
