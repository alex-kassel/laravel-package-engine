<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;

class UninstallPackagesCommand extends Command
{
    protected $signature = 'packages:uninstall {names?* : vendor/package list} {--all}';
    protected $description = 'composer remove for local package(s) (keeps repository entries and package folders)';

    public function handle(): int
    {
        $targets = [];
        if ($this->option('all')) {
            $base = base_path((string) config('laravel-package-engine.packages_path', 'packages'));
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
                    $this->error("Invalid name: {$n}");
                    return 1;
                }
                $targets[] = (string) $n;
            }
        }

        foreach ($targets as $name) {
            [$vendor, $package] = explode('/', $name);
            $this->removeComposerRequire($vendor, $package);
            $this->runComposerUpdate($vendor, $package);
            $this->removeVendorLink($vendor, $package);
            $this->info("Uninstalled: {$vendor}/{$package}");
        }
        return 0;
    }

    protected function removeComposerRequire(string $vendor, string $package): void
    {
        $composerFile = base_path('composer.json');
        $composer = json_decode((string) file_get_contents($composerFile), true);
        $packageName = "{$vendor}/{$package}";
        $changed = false;
        foreach (['require', 'require-dev'] as $section) {
            if (isset($composer[$section][$packageName])) {
                unset($composer[$section][$packageName]);
                $changed = true;
                $this->info("Removed {$packageName} from {$section} section");
            }
        }
        if ($changed) {
            file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    protected function runComposerUpdate(string $vendor, string $package): void
    {
        $pkg = "{$vendor}/{$package}";
        $this->info("Running composer update to remove {$pkg} ...");
        $process = new \Symfony\Component\Process\Process(['composer', 'update', $pkg]);
        $process->setTimeout(300);
        $process->run(function ($type, $buffer) { echo $buffer; });
        if (!$process->isSuccessful()) {
            $this->warn("Composer update encountered issues removing {$pkg}");
        }
    }

    protected function removeVendorLink(string $vendor, string $package): void
    {
        $link = base_path("vendor/{$vendor}/{$package}");
        if (!file_exists($link)) {
            return;
        }
        if (@is_link($link) && @unlink($link)) {
            $this->info("Removed symlink: {$link}");
            return;
        }
        if (is_dir($link)) {
            if (@rmdir($link)) {
                $this->info("Removed junction/directory: {$link}");
                return;
            }
        }
        $this->warn("Could not remove vendor link automatically: {$link}.");
    }
}
