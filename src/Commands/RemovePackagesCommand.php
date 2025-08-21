<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use AlexKassel\LaravelPackageEngine\Support\PackageManager;
use AlexKassel\LaravelPackageEngine\Support\LocalRegistry;

class RemovePackagesCommand extends Command
{
    protected $signature = 'packages:remove {names?* : vendor/package list} {--all} {--keep-dir}';
    protected $description = 'Uninstall local package(s) (composer remove if installed), remove path repository and optionally delete package dir. With --all, only packages created by this engine are targeted.';
    protected $aliases = ['packages:delete'];

    protected Filesystem $files;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    public function handle(): int
    {
        $pm = new PackageManager();
        $reg = new LocalRegistry();

        $targets = [];
        $explicit = false;
        if ($this->option('all')) {
            $targets = array_keys($reg->all());
            if (empty($targets)) {
                $this->warn('No self-created packages recorded; nothing to remove.');
                return 0;
            }
        } else {
            $explicit = true;
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
            $packageName = "$vendor/$package";

            // composer remove only if installed
            if ($pm->isRequiredInComposer($packageName)) {
                $this->info("Removing {$packageName} via composer remove ...");
                $pm->composerRemove($packageName);
            } else {
                $this->line("Composer dependency not present, skipping composer remove: {$packageName}");
            }

            // prefer registry path for repo removal
            $regMap = $reg->all();
            $resolvedPath = $regMap[$packageName] ?? $pm->resolvePackageDirectoryAcrossRoots($vendor, $package);
            if ($resolvedPath) {
                if ($pm->removeComposerRepositoryByPath($resolvedPath)) {
                    $this->info('Removed path repository: ' . $pm->relativePathFromBase($resolvedPath));
                }
            }

            // delete package directory unless --keep-dir
            if (!$this->option('keep-dir')) {
                $this->deletePackageDirByPath($resolvedPath ?: '');
            }

            // drop from registry if it was ours
            if (isset($regMap[$packageName])) {
                $reg->remove($packageName);
            }

            $this->info("Removed: {$vendor}/{$package}");
        }

        return 0;
    }

    protected function deletePackageDirByPath(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) { return; }
        $it = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $file) {
            if ($file->isDir()) { @rmdir($file->getPathname()); }
            else { @unlink($file->getPathname()); }
        }
        @rmdir($dir);
        $this->info("Deleted directory: {$dir}");

        // Clean up empty vendor directory
        $vendorDir = dirname($dir);
        if (is_dir($vendorDir)) {
            $isEmpty = true;
            $iterator = new \FilesystemIterator($vendorDir, \FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $item) { $isEmpty = false; break; }
            if ($isEmpty) { @rmdir($vendorDir); }
        }
    }
}
