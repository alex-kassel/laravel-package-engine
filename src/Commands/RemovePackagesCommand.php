<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class RemovePackagesCommand extends Command
{
    protected $signature = 'packages:remove {names?* : vendor/package list} {--all} {--keep-dir}';
    protected $description = 'Uninstall local package(s) from composer (require + repository), remove vendor link; delete package dir unless --keep-dir is given';
    protected $aliases = ['packages:delete'];

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
            [$vendor, $package] = explode('/', $name);
            $this->removeComposerRequire($vendor, $package);
            $this->removeComposerRepository($vendor, $package);
            $this->runComposerUpdate($vendor, $package);
            $this->removeVendorLink($vendor, $package);
            if (!$this->option('keep-dir')) {
                $this->deletePackageDir($vendor, $package);
            }
            $this->info("Removed: {$vendor}/{$package}");
        }

        return 0;
    }

    protected function removeComposerRepository(string $vendor, string $package): void
    {
        $composerFile = base_path('composer.json');
        $composer = json_decode(file_get_contents($composerFile), true);
    $repoPath = $this->resolveRepoUrl($vendor, $package);
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

    protected function deletePackageDir(string $vendor, string $package): void
    {
        $packagesRoot = (string) config('laravel-package-engine.packages_path', 'packages');
        $dir = base_path("{$packagesRoot}/{$vendor}/{$package}");
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
        $this->info("Deleted directory: {$dir}");
        
        // Clean up empty vendor directory
        $this->removeEmptyVendorDir($vendor);
    }

    protected function removeEmptyVendorDir(string $vendor): void
    {
        $packagesRoot = (string) config('laravel-package-engine.packages_path', 'packages');
        $vendorDir = base_path("{$packagesRoot}/{$vendor}");
        
        if (!is_dir($vendorDir)) {
            return;
        }
        
        // Check if vendor directory is empty (no files or directories)
        $isEmpty = true;
        $iterator = new \FilesystemIterator($vendorDir, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $isEmpty = false;
            break;
        }
        
        if ($isEmpty && @rmdir($vendorDir)) {
            $this->info("Removed empty vendor directory: {$vendorDir}");
        }
    }

    protected function resolveRepoUrl(string $vendor, string $package): string
    {
        $packagesRoot = (string) config('laravel-package-engine.packages_path', 'packages');
        $expected = base_path("{$packagesRoot}/{$vendor}/{$package}");
        $dir = $expected;
        if (!is_dir($expected)) {
            $vendorDir = base_path("{$packagesRoot}/{$vendor}");
            foreach (glob($vendorDir . '/*', GLOB_ONLYDIR) as $d) {
                $cj = $d . '/composer.json';
                if (is_file($cj)) {
                    $json = json_decode((string) @file_get_contents($cj), true);
                    if (($json['name'] ?? '') === "{$vendor}/{$package}") {
                        $dir = $d;
                        break;
                    }
                }
            }
        }
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR);
        $abs = rtrim($dir, DIRECTORY_SEPARATOR);
        if (str_starts_with($abs, $base)) {
            $rel = ltrim(substr($abs, strlen($base)), DIRECTORY_SEPARATOR);
            return str_replace('\\', '/', $rel);
        }
        return str_replace('\\', '/', $dir);
    }
}
