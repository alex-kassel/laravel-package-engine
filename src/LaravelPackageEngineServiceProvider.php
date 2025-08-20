<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine;

use Illuminate\Support\ServiceProvider;
use AlexKassel\LaravelPackageEngine\Commands\MakePackageCommand;
use AlexKassel\LaravelPackageEngine\Commands\InstallPackagesCommand;
use AlexKassel\LaravelPackageEngine\Commands\RemovePackagesCommand;
use AlexKassel\LaravelPackageEngine\Commands\ReinstallPackagesCommand;
use AlexKassel\LaravelPackageEngine\Commands\UninstallPackagesCommand;
use AlexKassel\LaravelPackageEngine\Commands\RemakePackagesCommand;

class LaravelPackageEngineServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laravel-package-engine.php',
            'laravel-package-engine'
        );

        // If config was published to a nested vendor/name path, load it explicitly
        $published = config_path('alex-kassel/laravel-package-engine/config.php');
        if (is_file($published)) {
            $current = $this->app['config']->get('laravel-package-engine', []);
            $overrides = require $published;
            if (is_array($overrides)) {
                $this->app['config']->set('laravel-package-engine', array_replace_recursive($current, $overrides));
            }
        }
    }

    public function boot()
    {
        // publish stubs so the user can edit them (under stubs/vendor/name)
        $this->publishes([
            __DIR__.'/../stubs/package' => base_path('stubs/alex-kassel/laravel-package-engine'),
        ], 'laravel-package-engine-stubs');

        // publish config to config/vendor/name/config.php
        $this->publishes([
            __DIR__.'/../config/laravel-package-engine.php' => config_path('alex-kassel/laravel-package-engine/config.php'),
        ], 'laravel-package-engine-config');

        // register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakePackageCommand::class,
                InstallPackagesCommand::class,
                RemovePackagesCommand::class,
                ReinstallPackagesCommand::class,
                UninstallPackagesCommand::class,
                RemakePackagesCommand::class,
            ]);
        }
    }
}
