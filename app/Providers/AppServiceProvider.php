<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;

use Illuminate\Support\Facades\Http;

use Illuminate\Support\ServiceProvider;

use Laravel\Sanctum\Sanctum;

use MongoDB\Laravel\Eloquent\Model;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        class_alias(PersonalAccessToken::class, \Laravel\Sanctum\PersonalAccessToken::class);

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Model::preventSilentlyDiscardingAttributes( app()->isLocal( ) );

        Http::macro('docker', function () {
            return
                Http::withHeader('Accept', 'application/json')
                    ->baseUrl('https://hub.docker.com/v2');
        });

        Http::macro('github', function () {
            return
                Http::withHeader('Accept',               'application/vnd.github+json')
                    ->withHeader('X-GitHub-Api-Version', '2022-11-28')
                    ->baseUrl('https://api.github.com');
        });
    }
}
