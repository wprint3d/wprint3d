<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Plugins\Contracts\PluginManager as PluginManagerContract;
use App\Plugins\PluginArchiveService;
use App\Plugins\PluginEffectExecutor;
use App\Plugins\PluginHookCompiler;
use App\Plugins\PluginHookDispatcher;
use App\Plugins\PluginLifecycleLogStore;
use App\Plugins\PluginManagerService;
use App\Plugins\PluginManifestValidator;
use App\Plugins\PluginPackager;
use App\Plugins\PluginRegistryClient;
use App\Plugins\PluginRuntimeRegistry;
use App\Plugins\PluginSignatureService;
use App\Plugins\Runtime\PluginRuntimeHttpClient;
use App\Plugins\Runtimes\BridgePluginRuntimeAdapter;
use App\Plugins\Runtimes\PhpPluginRuntimeAdapter;
use App\Support\FakeSerial\FakeSerialManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use MongoDB\Laravel\Eloquent\Model;

class AppServiceProvider extends ServiceProvider
{
    public static function shouldDispatchPluginAppBoot(
        bool $isTestingEnvironment,
        bool $hasMongoExtension,
        bool $runningInConsole,
        bool $dispatchOnHttp,
    ): bool {
        if ($isTestingEnvironment || ! $hasMongoExtension) {
            return false;
        }

        return $runningInConsole || $dispatchOnHttp;
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(PluginManifestValidator::class);
        $this->app->singleton(PluginSignatureService::class);
        $this->app->singleton(PluginArchiveService::class);
        $this->app->singleton(PluginPackager::class);
        $this->app->singleton(PluginRegistryClient::class);
        $this->app->singleton(PhpPluginRuntimeAdapter::class);
        $this->app->singleton(PluginRuntimeHttpClient::class);
        $this->app->singleton(BridgePluginRuntimeAdapter::class);
        $this->app->singleton(PluginRuntimeRegistry::class, function ($app) {
            return new PluginRuntimeRegistry([
                $app->make(PhpPluginRuntimeAdapter::class),
                $app->make(BridgePluginRuntimeAdapter::class),
            ]);
        });
        $this->app->singleton(PluginEffectExecutor::class);
        $this->app->singleton(PluginLifecycleLogStore::class);
        $this->app->singleton(PluginHookCompiler::class);
        $this->app->singleton(PluginManagerContract::class, PluginManagerService::class);
        $this->app->singleton(PluginHookDispatcher::class);
        $this->app->bind(FakeSerialManager::class, fn () => new FakeSerialManager(Cache::store()));
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if (! class_exists(\Laravel\Sanctum\PersonalAccessToken::class, false)) {
            class_alias(PersonalAccessToken::class, \Laravel\Sanctum\PersonalAccessToken::class);
        }

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Model::preventSilentlyDiscardingAttributes(app()->isLocal());

        Http::macro('docker', function () {
            return
                Http::withHeader('Accept', 'application/json')
                    ->baseUrl('https://hub.docker.com/v2');
        });

        Http::macro('github', function () {
            return
                Http::withHeader('Accept', 'application/vnd.github+json')
                    ->withHeader('X-GitHub-Api-Version', '2022-11-28')
                    ->baseUrl('https://api.github.com');
        });

        $this->app->booted(function () {
            if (! self::shouldDispatchPluginAppBoot(
                isTestingEnvironment: app()->environment('testing'),
                hasMongoExtension: extension_loaded('mongodb'),
                runningInConsole: app()->runningInConsole(),
                dispatchOnHttp: (bool) config('plugins.app_boot.dispatch_on_http', false),
            )) {
                return;
            }

            app(PluginHookDispatcher::class)->dispatch('app.boot', [
                'appVersion' => config('plugins.core_version'),
                'environment' => app()->environment(),
                'runningInConsole' => app()->runningInConsole(),
            ]);
        });
    }
}
