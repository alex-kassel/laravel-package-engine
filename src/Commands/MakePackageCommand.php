<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;

class MakePackageCommand extends Command
{
    protected $signature = 'packages:make {name : vendor/package} {--i|install} {--d|dev} {--branch=}';
    protected $description = 'Create a new local package under /packages (use --dev to install as dev dependency)';

    protected Filesystem $files;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    public function handle()
    {
        $name = $this->argument('name');
        if (!preg_match('#^[a-z0-9\-]+/[a-z0-9\-]+$#i', $name)) {
            $this->error('Name must be in vendor/package format.');
            return 1;
        }

        [$vendor, $package] = explode('/', $name);
        $vendorStudly = Str::studly(str_replace('-', ' ', $vendor));
        $packageStudly = Str::studly(str_replace('-', ' ', $package));
        $namespace = "{$vendorStudly}\\{$packageStudly}";
    $packagesRoot = config('laravel-package-engine.packages_path', 'packages');
    $basePath = base_path("{$packagesRoot}/{$vendor}/{$package}");

        if ($this->files->exists($basePath)) {
            $this->error("Package already exists: {$basePath}");
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
                'name' => "{$vendor}/{$package}",
        '--dev' => $this->option('dev'),
        '--branch' => $this->option('branch')
            ]);
        }

        return 0;
    }

    protected function copyStubs(string $basePath, array $replacements): void
    {
        $customStubRoot = base_path('stubs/alex-kassel/laravel-package-engine/package');
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
}
