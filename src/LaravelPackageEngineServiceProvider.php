<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine;

use Illuminate\Support\ServiceProvider;
use AlexKassel\LaravelPackageEngine\Commands\MakePackageCommand;
use AlexKassel\LaravelPackageEngine\Commands\InstallPackagesCommand;
use AlexKassel\LaravelPackageEngine\Commands\RemovePackagesCommand;
use AlexKassel\LaravelPackageEngine\Commands\ReinstallPackagesCommand;

class LaravelPackageEngineServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laravel-package-engine.php',
            'laravel-package-engine'
        );
    }

    public function boot()
    {
        // publish stubs so the user can edit them
        $this->publishes([
            __DIR__.'/../stubs/package' => base_path('stubs/alex-kassel/laravel-package-engine/package'),
        ], 'laravel-package-engine-stubs');

        // publish config
        $this->publishes([
            __DIR__.'/../config/laravel-package-engine.php' => config_path('laravel-package-engine.php'),
        ], 'laravel-package-engine-config');

        // register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakePackageCommand::class,
                InstallPackagesCommand::class,
                RemovePackagesCommand::class,
                ReinstallPackagesCommand::class,
            ]);
        }
    }
}
