<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class RemovePackagesCommand extends Command
{
    protected $signature = 'packages:remove {name? : vendor/package} {--all}';
    protected $description = 'Remove a local package from composer (require + repository) and delete vendor symlink/junction';

    protected Filesystem $files;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    public function handle(): int
    {
    $packagesPath = base_path((string) config('laravel-package-engine.packages_path', 'packages'));
        if (!is_dir($packagesPath)) {
            $this->error('No /packages directory found.');
            return 1;
        }

        $targets = [];

        if ($this->option('all')) {
            foreach (glob($packagesPath . '/*/*', GLOB_ONLYDIR) as $dir) {
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
            [$vendor, $package] = explode('/', $name);
            $this->removeComposerRequire($vendor, $package);
            $this->removeComposerRepository($vendor, $package);
            $this->runComposerUpdate($vendor, $package);
            $this->removeVendorLink($vendor, $package);
            $this->info("Removed: {$vendor}/{$package}");
        }

        return 0;
    }

    protected function removeComposerRepository(string $vendor, string $package): void
    {
        $composerFile = base_path('composer.json');
        $composer = json_decode(file_get_contents($composerFile), true);
    $repoPath = config('laravel-package-engine.packages_path', 'packages') . "/{$vendor}/{$package}";
        $repositories = $composer['repositories'] ?? [];
        $newRepos = [];
        $removed = false;
        foreach ($repositories as $repo) {
            if (!(($repo['type'] ?? '') === 'path' && ($repo['url'] ?? '') === $repoPath)) {
                $newRepos[] = $repo;
            } else {
                $removed = true;
            }
        }
        if ($removed) {
            $composer['repositories'] = array_values($newRepos);
            file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Removed repository from composer.json: {$repoPath}");
        }
    }

    protected function removeComposerRequire(string $vendor, string $package): void
    {
        $composerFile = base_path('composer.json');
        $composer = json_decode(file_get_contents($composerFile), true);
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
        $packageName = "{$vendor}/{$package}";
        $this->info("Running composer update to reflect removal of {$packageName} ...");
        $process = new Process(['composer', 'update']);
        $process->setTimeout(300);
        $process->run(function ($type, $buffer) {
            echo $buffer;
        });
        if (!$process->isSuccessful()) {
            $this->warn("Composer update encountered issues after removing {$packageName}");
        }
    }

    protected function removeVendorLink(string $vendor, string $package): void
    {
        $link = base_path("vendor/{$vendor}/{$package}");
        if (!file_exists($link)) {
            return;
        }
        // Try unlink first
        if (@is_link($link) && @unlink($link)) {
            $this->info("Removed symlink: {$link}");
            return;
        }
        // Windows junctions or directories
        if (is_dir($link)) {
            // Attempt rmdir (works for junctions if not in use)
            if (@rmdir($link)) {
                $this->info("Removed junction/directory: {$link}");
                return;
            }
        }
        // Fallback
        $this->warn("Could not remove vendor link automatically: {$link}. Remove manually if needed.");
    }
}
