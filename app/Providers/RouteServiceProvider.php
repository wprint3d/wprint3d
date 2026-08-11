<?php

namespace App\Providers;

use App\Plugins\PluginRuntimeRequestPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('octoprint')
                ->prefix('octoprint-api')
                ->group(base_path('routes/octoprint.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('plugin-runtime', function (Request $request) {
            $policy = app(PluginRuntimeRequestPolicy::class);
            $bucket = $policy->bucket($request);
            $limit = $policy->limit($bucket);

            return Limit::perMinute($limit)
                ->by($policy->identity($request, $bucket))
                ->response(fn (Request $limitedRequest, array $headers) => response()->json([
                    'message' => 'Plugin runtime request rate limit exceeded.',
                    'error' => [
                        'code' => 'plugin_runtime_rate_limited',
                        'bucket' => $bucket,
                        'limit' => $limit,
                        'retryAfter' => (int) ($headers['Retry-After'] ?? 1),
                    ],
                ], 429, $headers));
        });
    }
}
