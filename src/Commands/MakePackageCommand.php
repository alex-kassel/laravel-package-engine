<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;

class MakePackageCommand extends Command
{
    protected $signature = 'packages:make {names* : One or more vendor/package identifiers} {--i|install} {--d|dev} {--alias=} {--branch=}';
    protected $description = 'Create new local package(s) under /{packages_path} (use --dev to install as dev dependency)';

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

        $alias = $this->option('alias');
        if ($alias && count($names) > 1) {
            $this->warn('--alias is only applied when creating a single package. Ignoring for multiple names.');
            $alias = null;
        }

        $packagesRoot = (string) config('laravel-package-engine.packages_path', 'packages');

        // Ensure vendor root exists; if created now, add to .gitignore
        $packagesRootAbs = base_path($packagesRoot);
        $createdRoot = false;
        if (!is_dir($packagesRootAbs)) {
            $this->files->makeDirectory($packagesRootAbs, 0755, true);
            $createdRoot = true;
        }
        if ($createdRoot) {
            $this->ensurePackagesPathInGitignore($packagesRoot);
        }

        foreach ($names as $name) {
            if (!preg_match('#^[a-z0-9\-]+/[a-z0-9\-]+$#i', (string) $name)) {
                $this->error("Invalid name: {$name}. Use vendor/package.");
                return 1;
            }

            [$vendor, $package] = explode('/', $name);
            $vendorStudly = Str::studly(str_replace('-', ' ', $vendor));
            $packageStudly = Str::studly(str_replace('-', ' ', $package));
            $namespace = "{$vendorStudly}\\{$packageStudly}";

            $dirName = $alias ?: $package;
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
            $this->info("Added {$line} to .gitignore");
            return;
        }
        $content = file_get_contents($gi);
        if (strpos($content, $line) === false) {
            file_put_contents($gi, rtrim($content) . PHP_EOL . $line . PHP_EOL);
            $this->info("Added {$line} to .gitignore");
        }
    }
}
