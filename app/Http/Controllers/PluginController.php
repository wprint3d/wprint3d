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

    public function index(): array
    {
        return $this->pluginManager->listInstalled();
    }

    public function show(string $pluginId): array
    {
        return $this->pluginManager->get($pluginId);
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
        $enabled = (bool) config('plugins.development.enabled', false);

        return [
            'enabled' => $enabled,
            'mountPath' => config('plugins.development.mount_path'),
            'plugins' => $enabled ? $this->pluginManager->listDevelopmentPlugins() : [],
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
            if (! config('plugins.development.enabled', false)) {
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
}
