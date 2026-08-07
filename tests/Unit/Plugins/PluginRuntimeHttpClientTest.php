<?php

namespace Tests\Unit\Plugins;

use App\Plugins\Runtime\PluginRuntimeHttpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PluginRuntimeHttpClientTest extends TestCase
{
    public function test_resolves_persisted_runtime_state_and_trusted_headers_without_browser_input(): void
    {
        Http::fake(['http://cura-gateway:9311/*' => Http::response(['ok' => true])]);
        $token = Crypt::encryptString('secret-token');
        $plugin = [
            'plugin_id' => 'cura-web-ui',
            'manifest' => ['runtime' => ['baseUrl' => 'http://cura-gateway:9311']],
            'dependency_state' => ['runtime' => ['authTokenCiphertext' => $token]],
        ];

        $response = (new PluginRuntimeHttpClient)->get($plugin, '/health');

        $this->assertSame(200, $response->status());
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://cura-gateway:9311/health'
                && $request->hasHeader('Authorization', 'Bearer secret-token')
                && $request->hasHeader('x-wprint-plugin-id', 'cura-web-ui')
                && $request->hasHeader('x-wprint-user-id', 'wprint-system');
        });
    }

    public function test_rejects_unresolved_or_invalid_runtime_urls(): void
    {
        $this->expectException(\App\Plugins\Exceptions\PluginRuntimeException::class);
        (new PluginRuntimeHttpClient)->get([
            'plugin_id' => 'cura-web-ui',
            'manifest' => ['runtime' => ['baseUrl' => '']],
        ], '/health');
    }

    public function test_persisted_runtime_url_wins_over_stale_manifest_url(): void
    {
        Http::fake([
            'http://resolved-candidate:9311/*' => Http::response(['ok' => true]),
            'http://stale-runtime:9311/*' => Http::response(['ok' => false]),
        ]);

        (new PluginRuntimeHttpClient)->get([
            'plugin_id' => 'cura-web-ui',
            'manifest' => ['runtime' => ['baseUrl' => 'http://stale-runtime:9311']],
            'dependency_state' => ['runtime' => ['baseUrl' => 'http://resolved-candidate:9311']],
        ], '/health');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://resolved-candidate:9311/health');
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://stale-runtime:9311/health');
    }

    public function test_serialized_internal_plugin_payload_resolves_dependency_runtime_state(): void
    {
        Http::fake(['http://cura-gateway:9311/*' => Http::response(['ok' => true])]);
        $token = Crypt::encryptString('serialized-token');

        (new PluginRuntimeHttpClient)->get([
            'id' => 'cura-web-ui',
            'manifest' => ['runtime' => ['baseUrl' => 'http://cura-gateway:9311']],
            'dependencies' => [
                'runtime' => [
                    'baseUrl' => 'http://cura-gateway:9311',
                    'authTokenCiphertext' => $token,
                ],
            ],
        ], '/health');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer serialized-token'));
    }
}
