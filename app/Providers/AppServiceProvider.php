<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;

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

        Model::preventSilentlyDiscardingAttributes( app()->isLocal( ));
    }
}
