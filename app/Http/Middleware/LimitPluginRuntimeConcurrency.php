<?php

namespace App\Http\Middleware;

use App\Plugins\PluginRuntimeRequestPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class LimitPluginRuntimeConcurrency
{
    public function __construct(private readonly PluginRuntimeRequestPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bucket = $this->policy->bucket($request);
        if ($bucket !== PluginRuntimeRequestPolicy::BUCKET_PREVIEW) {
            return $next($request);
        }

        $store = Cache::store((string) config('plugins.runtime.concurrency_cache_store', 'redis'));
        $key = 'plugin-runtime-concurrency:'.$this->policy->identity($request, $bucket);
        $ttlSeconds = max(5, (int) config('plugins.runtime.preview_concurrency_ttl_seconds', 300));
        $limit = max(1, (int) config('plugins.runtime.preview_max_concurrent', 32));
        $store->add($key, 0, $ttlSeconds);
        $active = (int) $store->increment($key);

        if ($active > $limit) {
            $this->release($store, $key);

            return response()->json([
                'message' => 'Too many concurrent preview requests.',
                'error' => [
                    'code' => 'plugin_runtime_concurrency_limited',
                    'bucket' => $bucket,
                    'limit' => $limit,
                    'retryAfter' => 1,
                ],
            ], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => '1']);
        }

        try {
            return $next($request);
        } finally {
            $this->release($store, $key);
        }
    }

    private function release(mixed $store, string $key): void
    {
        if ((int) $store->decrement($key) <= 0) {
            $store->forget($key);
        }
    }
}
