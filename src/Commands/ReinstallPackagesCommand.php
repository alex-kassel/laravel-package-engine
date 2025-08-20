<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;

class ReinstallPackagesCommand extends Command
{
    protected $signature = 'packages:reinstall {names?* : vendor/package list} {--all} {--d|dev} {--branch=}';
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
            $names = (array) $this->argument('names');
            if (empty($names)) {
                $this->error('Please provide one or more vendor/package names or use --all.');
                return 1;
            }
            foreach ($names as $n) {
                if (!preg_match('#^[a-z0-9\-]+/[a-z0-9\-]+$#i', (string) $n)) {
                    $this->error("Invalid package name: {$n}");
                    return 1;
                }
                $targets[] = (string) $n;
            }
        }

        foreach ($targets as $name) {
            // Remove
            $this->call('packages:remove', ['names' => [$name]]);
            // Re-install with optional --dev
            $this->call('packages:install', [
                'names' => [$name],
                '--dev' => (bool) $this->option('dev'),
                '--branch' => $this->option('branch'),
            ]);
        }

        return 0;
    }
}
