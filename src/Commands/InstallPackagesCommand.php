<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class InstallPackagesCommand extends Command
{
    protected $signature = 'packages:install {name? : vendor/package} {--all} {--d|dev} {--branch=}';
    protected $description = 'Install a local package (composer repository + symlink, use --dev for dev dependencies)';

    protected Filesystem $files;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    public function handle()
    {
    $packagesPath = base_path((string) config('laravel-package-engine.packages_path', 'packages'));
        if (!is_dir($packagesPath)) {
            $this->error('No /packages directory found.');
            return 1;
        }

        $packages = [];

        if ($this->option('all')) {
            foreach (glob($packagesPath . '/*/*', GLOB_ONLYDIR) as $dir) {
                $package = basename($dir);
                $vendor = basename(dirname($dir));
                $packages[] = "{$vendor}/{$package}";
            }
        } else {
            $name = $this->argument('name');
            if (!$name || !preg_match('#^[a-z0-9\-]+/[a-z0-9\-]+$#i', $name)) {
                $this->error('Please provide a valid vendor/package name or use --all.');
                return 1;
            }
            $packages[] = $name;
        }

        foreach ($packages as $name) {
            [$vendor, $package] = explode('/', $name);
            $packagesRoot = (string) config('laravel-package-engine.packages_path', 'packages');
            $packagePath = base_path("{$packagesRoot}/{$vendor}/{$package}");
            if (!$this->files->exists($packagePath)) {
                $this->warn("Package not found: {$packagePath}");
                continue;
            }

            $this->addComposerRepository($vendor, $package);
            $this->addComposerRequire($vendor, $package);
            $this->runComposerUpdate($vendor, $package);
            $this->createSymlink($vendor, $package, $packagePath);
            $this->info("Installed: {$vendor}/{$package}");
        }

        return 0;
    }

    protected function addComposerRepository(string $vendor, string $package): void
    {
        $composerFile = base_path('composer.json');
        $composer = json_decode(file_get_contents($composerFile), true);

        $repoPath = config('laravel-package-engine.packages_path', 'packages') . "/{$vendor}/{$package}";
        $repositories = $composer['repositories'] ?? [];
        $already = false;
        foreach ($repositories as $repo) {
            if (($repo['type'] ?? '') === 'path' && ($repo['url'] ?? '') === $repoPath) {
                $already = true;
                break;
            }
        }
        if (!$already) {
            $composer['repositories'][] = [
                'type' => 'path',
                'url' => $repoPath,
                'options' => ['symlink' => true]
            ];
            file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Added repository to composer.json: {$repoPath}");
        }
    }

    protected function addComposerRequire(string $vendor, string $package): void
    {
        $composerFile = base_path('composer.json');
        $composer = json_decode(file_get_contents($composerFile), true);

        $packageName = "{$vendor}/{$package}";
        $requireType = $this->option('dev') ? 'require-dev' : 'require';
        $version = (string) ($this->option('branch') ?: config('laravel-package-engine.default_branch', 'dev-master'));

        if ($requireType === 'require-dev' && !isset($composer['require-dev'])) {
            $composer['require-dev'] = [];
        }

        if (!isset($composer[$requireType][$packageName]) || $composer[$requireType][$packageName] !== $version) {
            $otherType = $requireType === 'require' ? 'require-dev' : 'require';
            if (isset($composer[$otherType][$packageName])) {
                unset($composer[$otherType][$packageName]);
                $this->info("Removed {$packageName} from {$otherType} section");
            }

            // Set or update in the selected section
            $composer[$requireType][$packageName] = $version;
            file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Added {$packageName}:{$version} to {$requireType} section in composer.json");
        }
    }

    protected function runComposerUpdate(string $vendor, string $package): void
    {
        $packageName = "{$vendor}/{$package}";
        $this->info("Running composer update {$packageName} ...");
        $process = new Process(['composer', 'update', $packageName]);
        $process->setTimeout(300);
        $process->run(function ($type, $buffer) {
            echo $buffer;
        });
        if (!$process->isSuccessful()) {
            $this->warn("Composer update failed for {$packageName}");
        }
    }

    protected function createSymlink(string $vendor, string $package, string $packagePath): void
    {
        $vendorPath = base_path("vendor/{$vendor}");
        if (!is_dir($vendorPath)) {
            mkdir($vendorPath, 0777, true);
        }
        $link = base_path("vendor/{$vendor}/{$package}");
        if (file_exists($link)) {
            if (is_link($link)) {
                unlink($link);
            } else {
                $this->warn("Vendor path already exists and is not a symlink: {$link}");
                return;
            }
        }
        // Try native symlink first
        $ok = @symlink($packagePath, $link);
        if (!$ok) {
            // On Windows, fall back to directory junction to avoid permission issues
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $cmd = sprintf('cmd /C mklink /J "%s" "%s"', $link, $packagePath);
                $process = new \Symfony\Component\Process\Process([$this->cmdShell(), '/C', 'mklink', '/J', $link, $packagePath]);
                $process->run();
                if ($process->isSuccessful()) {
                    $this->info("Junction created: {$link} -> {$packagePath}");
                    return;
                }
                $this->warn("Failed to create junction (mklink) for {$link}. Output: " . $process->getErrorOutput());
            }
            // If still not ok, don't fail the whole install
            $this->warn("Symlink could not be created (permission denied). You can link manually if needed.");
            return;
        }
        $this->info("Symlink created: {$link} -> {$packagePath}");
    }

    private function cmdShell(): string
    {
        return '\\' === DIRECTORY_SEPARATOR ? 'cmd' : 'sh';
    }
}
