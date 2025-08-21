<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use AlexKassel\LaravelPackageEngine\Support\PackageManager;
use AlexKassel\LaravelPackageEngine\Support\LocalRegistry;

class InstallPackagesCommand extends Command
{
    protected $signature = 'packages:install {names?* : vendor/package list} {--all} {--d|dev} {--branch=}';
    protected $description = 'Install a local package (uses composer require; linking handled by Composer via path repository)';

    protected Filesystem $files;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    public function handle()
    {
        $pm = new PackageManager();
        $registry = new LocalRegistry();

        $packages = [];
        if ($this->option('all')) {
            $seen = [];
            foreach ($pm->packageRoots() as $root) {
                foreach (glob($root . '/*/*', GLOB_ONLYDIR) as $dir) {
                    $package = basename($dir);
                    $vendor = basename(dirname($dir));
                    $name = "$vendor/$package";
                    if (!isset($seen[$name])) { $packages[] = $name; $seen[$name] = true; }
                }
            }
            if (empty($packages)) {
                $this->warn('No local packages found under any packages* roots.');
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
                $packages[] = (string) $n;
            }
        }

        foreach ($packages as $name) {
            [$vendor, $package] = explode('/', $name);

            // 1) Registry path (created via packages:make)
            $regMap = $registry->all();
            $packagePath = $regMap[$name] ?? null;

            // 2) Known roots fallback
            if (!$packagePath) {
                $packagePath = $pm->resolvePackageDirectoryAcrossRoots($vendor, $package);
            }

            // 3) Path repositories scan as last resort
            if (!$packagePath) {
                $hits = $pm->findPackageInRepositories($vendor, $package);
                if (!empty($hits)) { $packagePath = $hits[0]; }
            }

            if (!$packagePath || !is_dir($packagePath)) {
                $this->warn("Package not found locally: {$name}");
                continue;
            }

            // Warn about potential conflicts (e.g., duplicate path repo or already required)
            foreach ($pm->detectConflicts($vendor, $package) as $msg) {
                $this->warn($msg);
            }

            // Ensure path repository exists so Composer can resolve and link
            $pm->ensureComposerRepository($packagePath);

            $branchInput = (string) ($this->option('branch') ?: config('laravel-package-engine.default_branch', 'dev-main'));
            $baseBranch = $pm->normalizeBranchBase($branchInput);
            $version = 'dev-' . $baseBranch;

            // Ensure local package composer.json has matching version
            $pkgComposerFile = $packagePath . DIRECTORY_SEPARATOR . 'composer.json';
            if (is_file($pkgComposerFile)) {
                $pkgComposer = json_decode((string) @file_get_contents($pkgComposerFile), true) ?: [];
                if (($pkgComposer['version'] ?? null) !== $version) {
                    $pkgComposer['version'] = $version;
                    @file_put_contents($pkgComposerFile, json_encode($pkgComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                }
            }

            $code = $pm->composerRequire($name, $version, (bool) $this->option('dev'));
            if ($code !== 0) {
                $this->warn("Composer require failed for {$name}");
            } else {
                $this->info("Installed: {$name}");
            }
        }

        return 0;
    }

    protected function resolvePackageDirectory(string $vendor, string $package): ?string
    {
        // kept for backward compatibility; now delegate to multi-root resolver
        return (new PackageManager())->resolvePackageDirectoryAcrossRoots($vendor, $package);
    }
}
