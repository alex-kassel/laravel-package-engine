<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;
use AlexKassel\LaravelPackageEngine\Support\LocalRegistry;

class ReinstallPackagesCommand extends Command
{
    protected $signature = 'packages:reinstall {names?* : vendor/package list} {--all} {--d|dev} {--branch=}';
    protected $description = 'Uninstall (keep local folder) and re-install local package(s): updates composer dependency and recreates link';

    public function handle(): int
    {
        $targets = [];
        if ($this->option('all')) {
            $reg = new LocalRegistry();
            $targets = array_keys($reg->all());
            if (empty($targets)) {
                $this->warn('No self-created packages recorded; nothing to reinstall.');
                return 0;
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
            // Uninstall (keeps path repo and local dir), then re-install
            $this->call('packages:uninstall', ['names' => [$name]]);
            $this->call('packages:install', [
                'names' => [$name],
                '--dev' => (bool) $this->option('dev'),
                '--branch' => $this->option('branch'),
            ]);
        }

        return 0;
    }
}
