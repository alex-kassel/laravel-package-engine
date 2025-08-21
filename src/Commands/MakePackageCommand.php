<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use AlexKassel\LaravelPackageEngine\Support\PackageManager;
use AlexKassel\LaravelPackageEngine\Support\LocalRegistry;

class MakePackageCommand extends Command
{
    protected $signature = 'packages:make {names* : One or more vendor/package identifiers} {--i|install} {--d|dev} {--branch=} {--path=}';
    protected $description = 'Create new local package(s) under /{packages_path} (use --dev to install as dev dependency)';
    protected $aliases = ['packages:create', 'packages:new'];

    protected Filesystem $files;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    public function handle()
    {
        $names = (array) $this->argument('names');
        if (empty($names)) {
            $this->error('Provide at least one vendor/package.');
            return 1;
        }

        // determine packages root with --path taking precedence
        $packagesRoot = (string) ($this->option('path') ?: config('laravel-package-engine.packages_path', 'packages'));

        // Ensure vendor root exists; if created now, add to .gitignore
        $packagesRootAbs = base_path($packagesRoot);
        if (!is_dir($packagesRootAbs)) {
            $this->files->makeDirectory($packagesRootAbs, 0755, true);
            $this->ensurePackagesPathInGitignore($packagesRoot);
        }

        $pm = new PackageManager();
        $registry = new LocalRegistry();

        foreach ($names as $name) {
            if (!preg_match('#^[a-z0-9\-]+/[a-z0-9\-]+$#i', (string) $name)) {
                $this->error("Invalid name: {$name}. Use vendor/package.");
                return 1;
            }

            [$vendor, $package] = explode('/', $name);

            // Conflict detection against require/require-dev and repositories
            $conflicts = $pm->detectConflicts($vendor, $package);
            if (!empty($conflicts)) {
                foreach ($conflicts as $msg) { $this->warn($msg); }
                // continue with creation, just warn
            }

            $vendorStudly = Str::studly(str_replace('-', ' ', $vendor));
            $packageStudly = Str::studly(str_replace('-', ' ', $package));
            $namespace = "{$vendorStudly}\\{$packageStudly}";

            $dirName = $package; // alias removed
            $basePath = base_path("{$packagesRoot}/{$vendor}/{$dirName}");
            if ($this->files->exists($basePath)) {
                $this->error("Package directory already exists: {$basePath}");
                return 1;
            }
            $this->files->makeDirectory($basePath, 0755, true);

            $this->copyStubs($basePath, [
                '{{ vendor }}' => $vendor,
                '{{ package }}' => $package,
                '{{ vendorStudly }}' => $vendorStudly,
                '{{ packageStudly }}' => $packageStudly,
                '{{ namespace }}' => $namespace,
                '{{ year }}' => date('Y'),
            ]);

            // Initialize git repo with proper default branch
            $branchBase = $pm->normalizeBranchBase((string) ($this->option('branch') ?: config('laravel-package-engine.default_branch', 'dev-main')));
            $pm->initGitRepo($basePath, $branchBase);

            // Ensure package composer.json has a version matching the branch base (e.g., dev-main)
            $pkgComposerFile = $basePath . DIRECTORY_SEPARATOR . 'composer.json';
            if (is_file($pkgComposerFile)) {
                $pkgComposer = json_decode((string) @file_get_contents($pkgComposerFile), true) ?: [];
                $expectedVersion = 'dev-' . $branchBase;
                if (!isset($pkgComposer['version']) || $pkgComposer['version'] !== $expectedVersion) {
                    $pkgComposer['version'] = $expectedVersion;
                    @file_put_contents($pkgComposerFile, json_encode($pkgComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                }
            }

            // Add composer path repository immediately (before install)
            $pm->ensureComposerRepository($basePath);

            // Track created package in local registry
            $registry->add("{$vendor}/{$package}", $basePath);

            $this->info("Package created at: {$basePath}");

            if ($this->option('install')) {
                $this->call('packages:install', [
                    'names' => ["{$vendor}/{$package}"],
                    '--dev' => $this->option('dev'),
                    '--branch' => $this->option('branch'),
                ]);
            }
        }

        return 0;
    }

    protected function copyStubs(string $basePath, array $replacements): void
    {
        $customStubRoot = base_path('stubs/alex-kassel/laravel-package-engine');
        $defaultStubRoot = __DIR__ . '/../../stubs/package';
        $stubRoot = is_dir($customStubRoot) ? $customStubRoot : $defaultStubRoot;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stubRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace($stubRoot, '', $item->getPathname());
            if (Str::endsWith($relativePath, '.stub')) {
                $relativePath = substr($relativePath, 0, -5);
            }
            $targetPath = $basePath . $relativePath;
            if ($item->isDir()) {
                $this->files->makeDirectory($targetPath, 0755, true, true);
            } else {
                $content = $this->files->get($item->getPathname());
                $content = str_replace(array_keys($replacements), array_values($replacements), $content);
                if (Str::endsWith($targetPath, '.php') && strpos($content, 'declare(strict_types=1);') === false) {
                    $content = preg_replace('/<\?php\s*/', "<?php\n\ndeclare(strict_types=1);\n\n", $content, 1);
                }
                $this->files->put($targetPath, $content);
            }
        }
    }

    protected function ensurePackagesPathInGitignore(string $packagesRoot): void
    {
        $gi = base_path('.gitignore');
        $line = '/' . trim($packagesRoot, '/') . '/';

        if (!file_exists($gi)) {
            file_put_contents($gi, $line . PHP_EOL);
            $this->info(".gitignore was created and {$line} was added");
            return;
        }

        $lines = file($gi, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $existing) {
            if (rtrim($existing, '/') === rtrim($line, '/')) {
                return;
            }
        }

        file_put_contents($gi, PHP_EOL . $line . PHP_EOL, FILE_APPEND);
        $this->info("{$line} was added to .gitignore");
    }
}
