<?php

namespace App\Http\Controllers;

use App\Models\Plugin;
use App\Plugins\Exceptions\PluginRuntimeException;
use GuzzleHttp\Psr7\PumpStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PluginRuntimeProxyController extends Controller
{
    public function forward(Request $request, string $pluginId, string $runtimePath = ''): Response
    {
        if (! $this->proxyEnabled()) {
            return $this->proxyDisabledResponse();
        }

        $plugin = $this->plugin($pluginId);
        if ($this->isWebSocketUpgrade($request)) {
            return response('WebSocket runtime upgrades are not supported; use polling.', 426, [
                'Upgrade' => 'HTTP/1.1',
            ]);
        }

        $maxBytes = $this->requestPayloadLimit($request, $plugin);
        if ((int) ($request->header('content-length') ?? 0) > $maxBytes) {
            return response('Runtime request exceeds the configured payload limit.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $path = '/'.ltrim($runtimePath, '/');
        $this->assertAllowedPath($plugin, $path);
        $this->assertAllowedMethod($plugin, $request->method());
        $baseUrl = $this->baseUrl($plugin);

        $headers = collect($request->headers->all())
            ->only(['accept', 'content-type', 'if-none-match', 'if-modified-since'])
            ->mapWithKeys(fn (array $values, string $key) => [$key => $values[0] ?? ''])
            ->all();
        $headers['x-wprint-user-id'] = (string) ($request->user()?->getAuthIdentifier() ?? 'wprint-user');
        $headers['x-wprint-plugin-id'] = $plugin->plugin_id;

        $client = Http::timeout($this->proxyTimeout($plugin, $this->isStreamingRequest($request)))
            ->withOptions(['stream' => true, 'allow_redirects' => false])
            ->withHeaders($headers);
        $client = $this->withRuntimeToken($client, $plugin);

        try {
            $response = $client->send($request->method(), rtrim($baseUrl, '/').$path, [
                'body' => $this->boundedRequestBody($request, $maxBytes),
            ]);
        } catch (RuntimeProxyPayloadTooLarge) {
            return response('Runtime request exceeds the configured payload limit.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $forwardedHeaders = collect($response->headers())
            ->only(['content-type', 'cache-control', 'etag', 'last-modified'])
            ->mapWithKeys(fn (array $values, string $key) => [$key => $values[0] ?? ''])
            ->all();

        $stream = $response->toPsrResponse()->getBody();

        return response()->stream(function () use ($stream): void {
            while (! $stream->eof()) {
                echo $stream->read(8192);
            }
        }, $response->status(), $forwardedHeaders);
    }

    public function importArtifact(Request $request, string $pluginId, string $importId): array|Response
    {
        if (! $this->proxyEnabled()) {
            return $this->proxyDisabledResponse();
        }

        $plugin = $this->plugin($pluginId);
        if (! data_get($plugin->manifest, 'runtime.proxy.enabled', false)) {
            throw new PluginRuntimeException('Runtime artifact import is disabled by the plugin manifest.');
        }

        $import = collect(data_get($plugin->manifest, 'runtime.artifactImports', []))->firstWhere('id', $importId);
        if (data_get($plugin->manifest, 'runtime.artifactImports', []) !== [] && ! is_array($import)) {
            throw new PluginRuntimeException('Runtime artifact import is not declared by the plugin manifest.');
        }
        $jobId = (string) $request->input('jobId', $importId);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $request->input('filename', "cura-{$jobId}.gcode")) ?: "cura-{$jobId}.gcode";

        if (! str_ends_with(strtolower($filename), '.gcode')) {
            $filename .= '.gcode';
        }

        $artifactPath = (string) $request->input('artifactPath', "/api/v1/jobs/{$jobId}/gcode");
        $this->assertAllowedPath($plugin, '/'.ltrim($artifactPath, '/'));
        $this->assertAllowedMethod($plugin, 'GET');
        if (is_array($import) && ! preg_match('~'.(string) $import['pathPattern'].'~', '/'.ltrim($artifactPath, '/'))) {
            throw new PluginRuntimeException('Runtime artifact path does not match the declared import pattern.');
        }
        $maxBytes = (int) config('plugins.runtime.max_artifact_import_bytes', 268435456);
        if ((int) ($request->header('content-length') ?? 0) > (int) config('plugins.runtime.max_payload_bytes', 262144)) {
            throw new PluginRuntimeException('Runtime artifact import request exceeds the configured payload limit.');
        }
        $client = Http::timeout($this->proxyTimeout($plugin, true))
            ->withOptions(['stream' => true, 'allow_redirects' => false])
            ->withHeaders([
                'x-wprint-user-id' => (string) ($request->user()?->getAuthIdentifier() ?? 'wprint-user'),
                'x-wprint-plugin-id' => $plugin->plugin_id,
            ]);
        $client = $this->withRuntimeToken($client, $plugin);
        $response = $client->get(rtrim($this->baseUrl($plugin), '/').'/'.ltrim($artifactPath, '/'));
        $response->throw();
        if (is_array($import) && ($contentTypes = $import['contentTypes'] ?? []) !== []) {
            $contentType = strtolower(trim(explode(';', (string) $response->header('content-type', ''))[0]));
            if (! in_array($contentType, array_map('strtolower', $contentTypes), true)) {
                throw new PluginRuntimeException('Runtime artifact content type is not allowed by the plugin manifest.');
            }
        }
        if (is_array($import)) {
            $maxBytes = min($maxBytes, (int) $import['maxSizeMb'] * 1048576);
        }
        if ((int) ($response->header('content-length') ?? 0) > $maxBytes) {
            throw new PluginRuntimeException('Runtime artifact exceeds the configured import limit.');
        }

        $path = 'cura/'.Str::uuid()->toString().'-'.$filename;
        $temporaryPath = $path.'.part';
        $disk = Storage::disk('gcode');
        try {
            $stream = $response->toPsrResponse()->getBody();
            $bounded = tmpfile();
            if (! is_resource($bounded)) {
                throw new PluginRuntimeException('Runtime artifact temporary storage could not be opened.');
            }

            try {
                $this->copyBoundedStream($stream, $bounded, $maxBytes);
                rewind($bounded);
                if (! $disk->put($temporaryPath, $bounded)) {
                    throw new PluginRuntimeException('Runtime artifact could not be staged.');
                }
            } finally {
                fclose($bounded);
            }

            if ($disk->size($temporaryPath) > $maxBytes) {
                throw new PluginRuntimeException('Runtime artifact exceeds the configured import limit.');
            }
            if (! $disk->move($temporaryPath, $path)) {
                throw new PluginRuntimeException('Runtime artifact could not be committed atomically.');
            }
        } finally {
            if ($disk->exists($temporaryPath)) {
                $disk->delete($temporaryPath);
            }
        }

        return [
            'importId' => $importId,
            'path' => $path,
            'name' => $filename,
            'downloadUrl' => url('/api/files/local/'.str_replace('%2F', '/', rawurlencode($path))),
        ];
    }

    private function plugin(string $pluginId): Plugin
    {
        $plugin = Plugin::where('plugin_id', $pluginId)->first();

        if (! $plugin || ! $plugin->enabled) {
            throw new PluginRuntimeException("Plugin {$pluginId} is not enabled.");
        }

        if (($plugin->manifest['runtime']['type'] ?? null) !== 'bridge') {
            throw new PluginRuntimeException("Plugin {$pluginId} does not expose a bridge runtime.");
        }

        if ((int) ($plugin->manifest['sdkRevision'] ?? 0) < 5 || ! is_array(data_get($plugin->manifest, 'runtime.httpProxy'))) {
            throw new PluginRuntimeException('Runtime proxy requires an SDK revision 5 httpProxy declaration.');
        }

        return $plugin;
    }

    private function proxyEnabled(): bool
    {
        return (bool) config('plugins.rollout.runtime_proxy_enabled', true);
    }

    private function proxyDisabledResponse(): Response
    {
        return response()->json([
            'message' => 'The plugin runtime proxy is temporarily disabled by the host.',
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function baseUrl(Plugin $plugin): string
    {
        $baseUrl = (string) data_get($plugin->dependency_state, 'runtime.baseUrl', $plugin->manifest['runtime']['baseUrl'] ?? '');

        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if ($baseUrl === '' || ! filter_var($baseUrl, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new PluginRuntimeException('Managed bridge runtime does not have a valid resolved HTTP URL.');
        }

        return $baseUrl;
    }

    private function requestPayloadLimit(Request $request, Plugin $plugin): int
    {
        $contentType = strtolower((string) $request->header('content-type', ''));

        $limit = str_starts_with($contentType, 'multipart/form-data')
            ? (int) config('plugins.runtime.max_upload_payload_bytes', 268435456)
            : (int) config('plugins.runtime.max_payload_bytes', 262144);

        if (str_starts_with($contentType, 'multipart/form-data')) {
            $declared = data_get(
                $plugin->manifest,
                'runtime.httpProxy.maxUploadMb',
                data_get($plugin->manifest, 'runtime.proxy.maxUploadMb'),
            );
            if (is_numeric($declared) && (int) $declared > 0) {
                $limit = min($limit, (int) $declared * 1048576);
            }
        }

        return max(1, $limit);
    }

    private function proxyTimeout(Plugin $plugin, bool $streaming = false): int
    {
        $manifestTimeout = data_get(
            $plugin->manifest,
            $streaming ? 'runtime.proxy.streamTimeoutSecs' : 'runtime.proxy.requestTimeoutSecs',
            config($streaming ? 'plugins.runtime.max_proxy_stream_timeout_secs' : 'plugins.runtime.proxy_timeout_secs', $streaming ? 1800 : 30),
        );
        $max = max(1, (int) config(
            $streaming ? 'plugins.runtime.max_proxy_stream_timeout_secs' : 'plugins.runtime.max_proxy_timeout_secs',
            $streaming ? 1800 : 300,
        ));

        return min($max, max(1, (int) $manifestTimeout));
    }

    private function isStreamingRequest(Request $request): bool
    {
        return str_starts_with(strtolower((string) $request->header('content-type', '')), 'multipart/form-data');
    }

    private function isWebSocketUpgrade(Request $request): bool
    {
        return strtolower((string) $request->header('upgrade', '')) === 'websocket'
            || str_contains(strtolower((string) $request->header('connection', '')), 'upgrade');
    }

    private function assertAllowedMethod(Plugin $plugin, string $method): void
    {
        $methods = data_get($plugin->manifest, 'runtime.proxy.methods', []);
        if ($methods !== [] && ! in_array(strtoupper($method), array_map('strtoupper', $methods), true)) {
            throw new PluginRuntimeException('Runtime proxy method is not allowed by the plugin manifest.');
        }
    }

    private function boundedRequestBody(Request $request, int $maxBytes): mixed
    {
        if ($request->method() === 'GET' || $request->method() === 'HEAD') {
            return null;
        }

        $source = $request->getContent(true);
        if (! is_resource($source)) {
            $body = (string) $request->getContent();
            if (strlen($body) > $maxBytes) {
                throw new RuntimeProxyPayloadTooLarge;
            }

            return $body;
        }

        $read = 0;

        return new PumpStream(function (int $length) use ($source, $maxBytes, &$read): string {
            if ($read >= $maxBytes) {
                $extra = fread($source, 1);
                if ($extra !== false && $extra !== '') {
                    throw new RuntimeProxyPayloadTooLarge;
                }

                return '';
            }

            $chunk = fread($source, min(max(1, $length), $maxBytes - $read + 1));
            if ($chunk === false || $chunk === '') {
                return '';
            }

            $read += strlen($chunk);
            if ($read > $maxBytes) {
                throw new RuntimeProxyPayloadTooLarge;
            }

            return $chunk;
        });
    }

    private function copyBoundedStream(mixed $source, mixed $destination, int $maxBytes): void
    {
        $written = 0;
        while (! $source->eof()) {
            $chunk = $source->read(min(8192, $maxBytes - $written + 1));
            if ($chunk === '') {
                break;
            }

            $written += strlen($chunk);
            if ($written > $maxBytes) {
                throw new PluginRuntimeException('Runtime artifact exceeds the configured import limit.');
            }

            if (fwrite($destination, $chunk) !== strlen($chunk)) {
                throw new PluginRuntimeException('Runtime artifact temporary storage could not be written.');
            }
        }
    }

    private function assertAllowedPath(Plugin $plugin, string $path): void
    {
        $allowed = data_get($plugin->manifest, 'runtime.proxy.allowedPaths', ['/api/v1']);
        $normalizedPath = '/'.ltrim((string) preg_replace('#/+#', '/', $path), '/');
        if (str_contains($normalizedPath, '/../') || str_ends_with($normalizedPath, '/..') || str_contains($normalizedPath, "\0")) {
            throw new PluginRuntimeException('Runtime proxy path is invalid.');
        }

        if ($allowed === [] || ! collect($allowed)->contains(function ($prefix) use ($normalizedPath): bool {
            $candidate = '/'.trim((string) $prefix, '/');

            return $normalizedPath === $candidate || str_starts_with($normalizedPath, $candidate.'/');
        })) {
            throw new PluginRuntimeException('Runtime proxy path is not allowed by the plugin manifest.');
        }
    }

    private function withRuntimeToken(\Illuminate\Http\Client\PendingRequest $client, Plugin $plugin): \Illuminate\Http\Client\PendingRequest
    {
        $ciphertext = data_get($plugin->dependency_state, 'runtime.authTokenCiphertext');

        if (is_string($ciphertext) && $ciphertext !== '') {
            try {
                return $client->withToken(Crypt::decryptString($ciphertext));
            } catch (\Throwable) {
                throw new PluginRuntimeException('Managed bridge runtime authentication token could not be decrypted.');
            }
        }

        return $client;
    }
}

final class RuntimeProxyPayloadTooLarge extends \RuntimeException {}
