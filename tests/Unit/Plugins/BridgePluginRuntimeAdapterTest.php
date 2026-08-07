<?php

namespace Tests\Unit\Plugins;

use App\Plugins\Runtimes\BridgePluginRuntimeAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BridgePluginRuntimeAdapterTest extends TestCase
{
    public function test_bridge_requests_include_runtime_token_and_trusted_identity_headers(): void
    {
        $ciphertext = Crypt::encryptString('bridge-token');
        Http::fake([
            'http://cura-web-ui-gateway:9311/*' => Http::response(['ok' => true], 200),
        ]);

        $adapter = new BridgePluginRuntimeAdapter;
        $result = $adapter->invokeAction([
            'plugin_id' => 'cura-web-ui',
            'manifest' => [
                'runtime' => ['baseUrl' => 'http://cura-web-ui-gateway:9311'],
            ],
            'dependency_state' => [
                'runtime' => ['authTokenCiphertext' => $ciphertext],
            ],
        ], ['id' => 'capabilities', 'path' => '/api/v1/capabilities'], [], []);

        $this->assertSame(['ok' => true], $result);
        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Bearer bridge-token')
                && $request->hasHeader('x-wprint-plugin-id', 'cura-web-ui')
                && $request->hasHeader('x-wprint-user-id', 'wprint-system');
        });
    }
}
