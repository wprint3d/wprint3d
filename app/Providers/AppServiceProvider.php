<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use Jenssegers\Mongodb\Eloquent\Model;

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
        Model::preventSilentlyDiscardingAttributes( app()->isLocal( ));
    }
}
