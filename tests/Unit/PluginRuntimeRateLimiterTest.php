<?php

namespace Tests\Unit;

use App\Http\Middleware\LimitPluginRuntimeConcurrency;
use App\Plugins\PluginRuntimeRequestPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PluginRuntimeRateLimiterTest extends TestCase
{
    public function test_runtime_routes_replace_the_global_api_limit_with_the_scoped_limiter(): void
    {
        $routes = collect(app('router')->getRoutes());
        foreach ([
            'api/plugins/{pluginId}/runtime/{runtimePath?}',
            'api/plugins/{pluginId}/runtime-artifacts/{importId}',
        ] as $uri) {
            $route = $routes->first(fn ($candidate) => $candidate->uri() === $uri);

            $this->assertNotNull($route, "Runtime route {$uri} was not registered.");
            $this->assertContains('throttle:1500,1', $route->excludedMiddleware());
            $this->assertContains('throttle:plugin-runtime', $route->gatherMiddleware());
            $this->assertNotContains('throttle:1500,1', $route->gatherMiddleware());
            if (str_contains($uri, '/runtime/')) {
                $this->assertContains('plugin-runtime.concurrent', $route->gatherMiddleware());
            }
        }
    }

    public function test_runtime_request_classes_use_independent_rate_limit_buckets(): void
    {
        $limiter = RateLimiter::limiter('plugin-runtime');

        $metadata = $limiter($this->runtimeRequest('GET', '/api/plugins/cura-web-ui/runtime/api/v2/machines/catalog'));
        $settingsResolve = $limiter($this->runtimeRequest('POST', '/api/plugins/cura-web-ui/runtime/api/v2/settings/resolve', 'application/json'));
        $jobLayer = $limiter($this->runtimeRequest('GET', '/api/plugins/cura-web-ui/runtime/api/v2/slice-jobs/job-1/artifacts/layers/5'));
        $asset = $limiter($this->runtimeRequest('GET', '/api/plugins/cura-web-ui/runtime/api/v2/assets/asset-1/display.glb'));
        $sse = $limiter($this->runtimeRequest('GET', '/api/plugins/cura-web-ui/runtime/api/v2/slice-jobs/job-1/events'));
        $upload = $limiter($this->runtimeRequest('POST', '/api/plugins/cura-web-ui/runtime/api/v2/assets/import', 'multipart/form-data; boundary=test'));
        $paint = $limiter($this->runtimeRequest('POST', '/api/plugins/cura-web-ui/runtime/api/v2/paint-textures', 'multipart/form-data; boundary=test'));
        $jobMutation = $limiter($this->runtimeRequest('POST', '/api/plugins/cura-web-ui/runtime/api/v2/slice-jobs', 'application/json'));
        $artifact = $limiter($this->runtimeRequest('POST', '/api/plugins/cura-web-ui/runtime-artifacts/job-1', 'application/json'));

        $this->assertSame(3000, $metadata->maxAttempts);
        $this->assertSame(60, $settingsResolve->maxAttempts);
        $this->assertSame(60000, $jobLayer->maxAttempts);
        $this->assertSame(60000, $asset->maxAttempts);
        $this->assertSame(120, $sse->maxAttempts);
        $this->assertSame(120, $upload->maxAttempts);
        $this->assertSame(120, $paint->maxAttempts);
        $this->assertSame(60, $jobMutation->maxAttempts);
        $this->assertSame(60, $artifact->maxAttempts);
        $this->assertNotSame($metadata->key, $settingsResolve->key);
        $this->assertNotSame($metadata->key, $jobLayer->key);
        $this->assertSame($jobLayer->key, $asset->key);
        $this->assertNotSame($jobLayer->key, $sse->key);
        $this->assertNotSame($metadata->key, $upload->key);
        $this->assertSame($upload->key, $paint->key);
        $this->assertSame($settingsResolve->key, $jobMutation->key);
        $this->assertNotSame($upload->key, $artifact->key);
        $this->assertStringEndsWith(':metadata', $metadata->key);
        $this->assertStringEndsWith(':preview', $jobLayer->key);
        $this->assertStringEndsWith(':sse', $sse->key);
        $this->assertStringEndsWith(':upload', $upload->key);
        $this->assertStringEndsWith(':mutation', $artifact->key);
    }

    public function test_preview_concurrency_limit_returns_a_structured_retryable_429(): void
    {
        config()->set('plugins.runtime.concurrency_cache_store', 'array');
        config()->set('plugins.runtime.preview_max_concurrent', 1);
        $request = $this->runtimeRequest('GET', '/api/plugins/cura-web-ui/runtime/api/v2/assets/asset-1/display.glb');
        $policy = app(PluginRuntimeRequestPolicy::class);
        $key = 'plugin-runtime-concurrency:'.$policy->identity($request, PluginRuntimeRequestPolicy::BUCKET_PREVIEW);
        Cache::store('array')->put($key, 1, 60);

        $response = app(LimitPluginRuntimeConcurrency::class)->handle(
            $request,
            fn () => new Response('unexpected', 200),
        );

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('1', $response->headers->get('Retry-After'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('plugin_runtime_concurrency_limited', $payload['error']['code']);
        $this->assertSame('preview', $payload['error']['bucket']);
    }

    private function runtimeRequest(string $method, string $path, string $contentType = ''): Request
    {
        $request = Request::create($path, $method, server: $contentType === '' ? [] : ['CONTENT_TYPE' => $contentType]);
        $runtimePath = str_contains($path, '/runtime/') ? explode('/runtime/', $path, 2)[1] : '';
        $request->setRouteResolver(fn () => new class($runtimePath)
        {
            public function __construct(private readonly string $runtimePath) {}

            public function parameter(string $name, mixed $default = null): mixed
            {
                return match ($name) {
                    'pluginId' => 'cura-web-ui',
                    'runtimePath' => $this->runtimePath,
                    default => $default,
                };
            }
        });

        return $request;
    }
}
