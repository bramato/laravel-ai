<?php

namespace Bramato\LaravelAi;

use Illuminate\Support\ServiceProvider;

class LaravelAiServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        // Bootstrap package services (routes, views, config, etc.)

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/laravel-ai.php' => config_path('laravel-ai.php'),
            ], 'config');

            // Publishing the views.
            /*$this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/laravel-ai'),
            ], 'views');*/

            // Publishing assets.
            /*$this->publishes([
                __DIR__.'/../resources/assets' => public_path('vendor/laravel-ai'),
            ], 'assets');*/

            // Publishing the translation files.
            /*$this->publishes([
                __DIR__.'/../resources/lang' => resource_path('lang/vendor/laravel-ai'),
            ], 'lang');*/

            // Registering package commands.
            // $this->commands([]);
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__ . '/../config/laravel-ai.php', 'laravel-ai');

        // Register the main class to use with the facade
        $this->app->singleton('laravel-ai', function () {
            // NOTE: You need to create the Bramato\LaravelAi\LaravelAi class
            return new LaravelAi;
        });
    }
}
