<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;
use AlexKassel\LaravelPackageEngine\Support\PackageManager;
use AlexKassel\LaravelPackageEngine\Support\LocalRegistry;

class UninstallPackagesCommand extends Command
{
    protected $signature = 'packages:uninstall {names?* : vendor/package list} {--all}';
    protected $description = 'composer remove for local package(s) (keeps repositories and package folders). With --all, only packages created by this engine are targeted.';

    public function handle(): int
    {
        $targets = [];
        if ($this->option('all')) {
            $reg = new LocalRegistry();
            $targets = array_keys($reg->all());
            if (empty($targets)) {
                $this->warn('No self-created packages recorded; nothing to uninstall.');
                return 0;
            }
        } else {
            $names = (array) $this->argument('names');
            if (empty($names)) {
                $this->error('Provide one or more vendor/package names or use --all.');
                return 1;
            }
            foreach ($names as $n) {
                if (!preg_match('#^[a-z0-9\-]+/[a-z0-9\-]+$#i', (string) $n)) {
                    $this->error("Invalid name: {$n}");
                    return 1;
                }
                $targets[] = (string) $n;
            }
        }

        $pm = new \AlexKassel\LaravelPackageEngine\Support\PackageManager();

        foreach ($targets as $name) {
            if ($pm->isRequiredInComposer($name)) {
                $this->info("Removing {$name} via composer remove ...");
                $pm->composerRemove($name);
            } else {
                $this->line("Dependency not present, skipping composer remove: {$name}");
            }
            $this->info("Uninstalled: {$name}");
        }
        return 0;
    }
}
