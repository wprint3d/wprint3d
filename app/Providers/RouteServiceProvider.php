<?php

namespace App\Providers;

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
            $pluginId = (string) ($request->route('pluginId') ?? 'unknown');
            $contentType = strtolower((string) $request->header('content-type', ''));
            $isArtifact = str_contains((string) $request->path(), 'runtime-artifacts/');
            $isUpload = str_starts_with($contentType, 'multipart/form-data')
                || in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH'], true);
            $bucket = $isArtifact ? 'artifact' : ($isUpload ? 'upload' : 'metadata');
            $limit = match ($bucket) {
                'artifact' => (int) config('plugins.runtime.proxy_rate_limits.artifact_per_minute', 30),
                'upload' => (int) config('plugins.runtime.proxy_rate_limits.upload_per_minute', 10),
                default => (int) config('plugins.runtime.proxy_rate_limits.metadata_per_minute', 120),
            };

            return Limit::perMinute(max(1, $limit))->by(($request->user()?->getAuthIdentifier() ?? $request->ip()).':'.$pluginId.':'.$bucket);
        });
    }
}
