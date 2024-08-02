<?php

namespace Sabbir268\InduxiaCurd;

use Illuminate\Support\ServiceProvider;

class InduxiaCurdServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Register package services
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Sabbir268\InduxiaCurd\Commands\GenerateCrudCommand::class,
                \Sabbir268\InduxiaCurd\Commands\GenerateYamlCommand::class,
            ]);
        }

        // // Load routes, views, migrations, etc.
        // $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        // $this->loadViewsFrom(__DIR__ . '/../resources/views', 'induxia-curd');
        // $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/induxia-curd.php' => config_path('induxia-curd.php'),
        ], 'config');
    }
}
