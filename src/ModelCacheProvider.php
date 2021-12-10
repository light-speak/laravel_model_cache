<?php

namespace LightSpeak\ModelCache;

use Illuminate\Support\ServiceProvider;

class ModelCacheProvider extends ServiceProvider
{
    public function boot()
    {

    }

    public function register()
    {
//        $this->app->singleton('model_cache', function ($app) {
//            return new ModelCache();
//        });
    }
}
