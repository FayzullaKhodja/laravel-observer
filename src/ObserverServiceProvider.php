<?php

namespace Company\Observer;

use Illuminate\Support\ServiceProvider;

class ObserverServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observer.php', 'observer');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/observer.php' => config_path('observer.php'),
            ], 'observer-config');
        }
    }
}
