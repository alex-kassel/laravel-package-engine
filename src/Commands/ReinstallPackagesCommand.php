<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;

class ReinstallPackagesCommand extends Command
{
    protected $signature = 'packages:reinstall {name? : vendor/package} {--all} {--d|dev} {--branch=}';
    protected $description = 'Remove and re-install local package(s): updates composer repository/require and recreates link';

    public function handle(): int
    {
        $targets = [];
        if ($this->option('all')) {
            $base = base_path((string) config('laravel-package-engine.packages_path', 'packages'));
            foreach (glob($base . '/*/*', GLOB_ONLYDIR) as $dir) {
                $package = basename($dir);
                $vendor = basename(dirname($dir));
                $targets[] = "{$vendor}/{$package}";
            }
        } else {
            $name = (string) $this->argument('name');
            if (!$name || !preg_match('#^[a-z0-9\-]+/[a-z0-9\-]+$#i', $name)) {
                $this->error('Please provide a valid vendor/package name or use --all.');
                return 1;
            }
            $targets[] = $name;
        }

        foreach ($targets as $name) {
            // Remove
            $this->call('packages:remove', ['name' => $name]);
            // Re-install with optional --dev
            $this->call('packages:install', [
                'name' => $name,
                '--dev' => (bool) $this->option('dev'),
                '--branch' => $this->option('branch'),
            ]);
        }

        return 0;
    }
}
