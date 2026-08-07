<?php

namespace App\Plugins\Runtime;

use App\Plugins\Exceptions\PluginRuntimeException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Host-owned HTTP boundary for managed plugin runtimes.
 *
 * Runtime URLs, bearer credentials, and trusted identity headers are resolved
 * from WPrint's persisted dependency state. Browser payloads never reach this
 * client and callers cannot provide an arbitrary host URL or token.
 */
class PluginRuntimeHttpClient
{
    public function request(array $plugin, bool $stream = false): PendingRequest
    {
        $runtime = data_get($plugin, 'manifest.runtime', []);
        $baseUrl = (string) (
            data_get($plugin, 'dependency_state.runtime.baseUrl')
            ?? data_get($plugin, 'dependencies.runtime.baseUrl')
            ?? ($runtime['baseUrl'] ?? '')
        );

        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if ($baseUrl === '' || ! filter_var($baseUrl, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new PluginRuntimeException('Managed plugin runtime has no valid resolved base URL.');
        }

        $headers = [
            'x-wprint-plugin-id' => (string) data_get($plugin, 'plugin_id', data_get($plugin, 'manifest.id', 'plugin')),
            'x-wprint-user-id' => (string) data_get($plugin, 'runtime.userId', data_get($plugin, 'dependency_state.runtime.userId', 'wprint-system')),
        ];
        $request = Http::acceptJson()
            ->withHeaders($headers)
            ->withOptions(['allow_redirects' => false])
            ->baseUrl(rtrim($baseUrl, '/'));

        $ciphertext = data_get($plugin, 'dependencies.runtime.authTokenCiphertext')
            ?? data_get($plugin, 'dependency_state.runtime.authTokenCiphertext')
            ?? data_get($plugin, 'manifest.runtime.authTokenCiphertext');
        if (is_string($ciphertext) && $ciphertext !== '') {
            try {
                $request = $request->withToken(Crypt::decryptString($ciphertext));
            } catch (\Throwable) {
                throw new PluginRuntimeException('Managed plugin runtime authentication token could not be decrypted.');
            }
        }

        return $stream ? $request->withOptions(['stream' => true]) : $request;
    }

    public function get(array $plugin, string $path, bool $stream = false): \Illuminate\Http\Client\Response
    {
        return $this->request($plugin, $stream)
            ->timeout((int) config('plugins.runtime.bridge_timeout_secs', 5))
            ->get($path);
    }

    public function post(array $plugin, string $path, array $payload): \Illuminate\Http\Client\Response
    {
        return $this->request($plugin)
            ->timeout((int) config('plugins.runtime.bridge_timeout_secs', 5))
            ->post($path, $payload);
    }
}
