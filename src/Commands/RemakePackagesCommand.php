<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;

class RemakePackagesCommand extends Command
{
    protected $signature = 'packages:remake {names?* : vendor/package list or --all} {--all} {--i|install} {--d|dev} {--alias=} {--branch=}';
    protected $description = 'Remove and recreate package(s) (delete dir, re-create from stubs, optional install)';

    public function handle(): int
    {
        $targets = [];
        $packagesRoot = (string) config('laravel-package-engine.packages_path', 'packages');
        $base = base_path($packagesRoot);
        if ($this->option('all')) {
            if (!is_dir($base)) {
                $this->error('No /packages directory found.');
                return 1;
            }
            foreach (glob($base . '/*/*', GLOB_ONLYDIR) as $dir) {
                $package = basename($dir);
                $vendor = basename(dirname($dir));
                $targets[] = "{$vendor}/{$package}";
            }
        } else {
            $names = (array) $this->argument('names');
            if (empty($names)) {
                $this->error('Provide one or more vendor/package names or use --all.');
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

        $alias = $this->option('alias');
        if ($alias && count($targets) > 1) {
            $this->warn('--alias is only applied for a single package. Ignoring for multiple targets.');
            $alias = null;
        }

        foreach ($targets as $name) {
            // Remove, then re-create (deletes dir by default)
            $this->call('packages:remove', ['names' => [$name]]);
            $args = [
                'names' => [$name],
                '--install' => (bool) $this->option('install'),
                '--dev' => (bool) $this->option('dev'),
                '--branch' => $this->option('branch'),
            ];
            if ($alias) {
                $args['--alias'] = $alias;
            }
            $this->call('packages:make', $args);
        }

        return 0;
    }
}
