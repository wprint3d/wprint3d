<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PluginRuntimeRateLimiterTest extends TestCase
{
    public function test_runtime_request_classes_use_independent_rate_limit_buckets(): void
    {
        $limiter = RateLimiter::limiter('plugin-runtime');

        $metadata = $limiter($this->runtimeRequest('GET', '/api/plugins/cura-web-ui/runtime/api/v1/capabilities'));
        $upload = $limiter($this->runtimeRequest('POST', '/api/plugins/cura-web-ui/runtime/api/v1/jobs', 'application/json'));
        $artifact = $limiter($this->runtimeRequest('POST', '/api/plugins/cura-web-ui/runtime-artifacts/job-1', 'application/json'));

        $this->assertSame(120, $metadata->maxAttempts);
        $this->assertSame(10, $upload->maxAttempts);
        $this->assertSame(30, $artifact->maxAttempts);
        $this->assertNotSame($metadata->key, $upload->key);
        $this->assertNotSame($upload->key, $artifact->key);
        $this->assertStringEndsWith(':metadata', $metadata->key);
        $this->assertStringEndsWith(':upload', $upload->key);
        $this->assertStringEndsWith(':artifact', $artifact->key);
    }

    private function runtimeRequest(string $method, string $path, string $contentType = ''): Request
    {
        $request = Request::create($path, $method, server: $contentType === '' ? [] : ['CONTENT_TYPE' => $contentType]);
        $request->setRouteResolver(fn () => new class
        {
            public function parameter(string $name, mixed $default = null): mixed
            {
                return $name === 'pluginId' ? 'cura-web-ui' : $default;
            }
        });

        return $request;
    }
}
