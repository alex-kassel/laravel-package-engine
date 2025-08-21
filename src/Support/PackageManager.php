<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Support;

use Symfony\Component\Process\Process;

class PackageManager
{
    public function __construct() {}

    /**
     * Return absolute paths of all local package roots, e.g. base_path()/packages*,
     * including the configured root and a default 'packages' fallback.
     */
    public function packageRoots(): array
    {
        $roots = [];
        $configured = (string) config('laravel-package-engine.packages_path', 'packages');
        $roots[] = base_path($configured);
        if ($configured !== 'packages') {
            $roots[] = base_path('packages');
        }
        // discover siblings that start with 'packages'
        foreach (glob(base_path('packages*')) as $path) {
            if (is_dir($path)) { $roots[] = $path; }
        }
        // dedupe + existing only
        $unique = [];
        foreach ($roots as $r) {
            $real = rtrim($r, DIRECTORY_SEPARATOR);
            if (is_dir($real)) { $unique[$real] = true; }
        }
        return array_keys($unique);
    }

    /**
     * Resolve the package directory across all known package roots.
     */
    public function resolvePackageDirectoryAcrossRoots(string $vendor, string $package): ?string
    {
        foreach ($this->packageRoots() as $root) {
            $expected = $root . DIRECTORY_SEPARATOR . $vendor . DIRECTORY_SEPARATOR . $package;
            if (is_dir($expected)) {
                return $expected;
            }
            $vendorDir = $root . DIRECTORY_SEPARATOR . $vendor;
            if (!is_dir($vendorDir)) { continue; }
            foreach (glob($vendorDir . '/*', GLOB_ONLYDIR) as $dir) {
                $cj = $dir . '/composer.json';
                if (is_file($cj)) {
                    $json = json_decode((string) @file_get_contents($cj), true);
                    if (($json['name'] ?? '') === "$vendor/$package") {
                        return $dir;
                    }
                }
            }
        }
        return null;
    }

    public function getRootComposer(): array
    {
        $composerFile = base_path('composer.json');
        $composer = json_decode((string) @file_get_contents($composerFile), true);
        return is_array($composer) ? $composer : [];
    }

    public function isPackageRequired(string $packageName): ?string
    {
        $composer = $this->getRootComposer();
        foreach (['require', 'require-dev'] as $section) {
            if (isset($composer[$section][$packageName])) {
                return $section;
            }
        }
        return null;
    }

    public function repositories(): array
    {
        $composer = $this->getRootComposer();
        return is_array($composer['repositories'] ?? []) ? $composer['repositories'] : [];
    }

    protected function globLike(string $pattern): bool
    {
        return strpbrk($pattern, '*?[') !== false;
    }

    protected function globToRegex(string $pattern): string
    {
        $p = str_replace('\\', '/', $pattern);
        $p = preg_quote($p, '#');
        $p = str_replace(['\\*', '\\?'], ['.*', '.'], $p);
        // basic character class support not implemented; treat [ as literal
        return '#^' . $p . '$#i';
    }

    public function ensureComposerRepository(string $absPath): bool
    {
        $composerFile = base_path('composer.json');
        $composer = $this->getRootComposer();
        $repoPath = $this->relativePathFromBase($absPath);
        $repositories = $composer['repositories'] ?? [];
        foreach ($repositories as $repo) {
            if (($repo['type'] ?? '') !== 'path' || !isset($repo['url'])) { continue; }
            $url = str_replace('\\', '/', (string) $repo['url']);
            if ($this->globLike($url)) {
                if (fnmatch($url, $repoPath)) {
                    return false; // covered by a glob repo
                }
            } else {
                if ($url === $repoPath) {
                    return false; // exact
                }
            }
        }
        $composer['repositories'][] = [
            'type' => 'path',
            'url' => $repoPath,
            'options' => ['symlink' => true, 'canonical' => true],
        ];
        file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        return true;
    }

    /**
     * Find directories in repositories that contain the given package (by composer.json name).
     * Returns an array of absolute paths.
     */
    public function findPackageInRepositories(string $vendor, string $package): array
    {
        $hits = [];
        $name = $vendor . '/' . $package;
        foreach ($this->repositories() as $repo) {
            if (($repo['type'] ?? '') !== 'path' || !isset($repo['url'])) { continue; }
            $url = (string) $repo['url'];
            $abs = base_path($url);
            $candidates = [];
            if ($this->globLike($url)) {
                foreach (glob($abs) as $cand) { $candidates[] = $cand; }
            } else {
                $candidates[] = $abs;
            }
            foreach ($candidates as $cand) {
                if (!is_dir($cand)) { continue; }
                $paths = [$cand];
                // If candidate seems to be a vendor dir (no composer.json), scan one level deeper
                if (!is_file($cand . '/composer.json')) {
                    foreach (glob($cand . '/*', GLOB_ONLYDIR) as $child) { $paths[] = $child; }
                }
                foreach ($paths as $p) {
                    $cj = $p . '/composer.json';
                    if (!is_file($cj)) { continue; }
                    $json = json_decode((string) @file_get_contents($cj), true);
                    if (($json['name'] ?? null) === $name) {
                        $real = realpath($p) ?: $p;
                        if (!in_array($real, $hits, true)) {
                            $hits[] = $real;
                        }
                    }
                }
            }
        }
        // dedupe
        $map = [];
        foreach ($hits as $h) { $map[$this->normalizeSep($h)] = true; }
        return array_keys($map);
    }

    protected function normalizeSep(string $p): string
    {
        return str_replace('\\', '/', $p);
    }

    /**
     * Detect potential conflicts before creating/installing a package.
     * Returns an array of human-readable messages.
     */
    public function detectConflicts(string $vendor, string $package): array
    {
        $conflicts = [];
        $name = $vendor . '/' . $package;
        if ($section = $this->isPackageRequired($name)) {
            $conflicts[] = "Package {$name} is already listed in composer.json {$section}.";
        }
        $paths = $this->findPackageInRepositories($vendor, $package);
        foreach ($paths as $p) {
            $conflicts[] = "Package {$name} is already found via a path repository at: " . $this->relativePathFromBase($p);
        }
        return $conflicts;
    }

    public function composerRequire(string $packageName, string $version, bool $dev = false): int
    {
        $args = ['composer', 'require', sprintf('%s:%s', $packageName, $version)];
        if ($dev) { $args[] = '--dev'; }
        $process = new Process($args, base_path());
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) { echo $buffer; });
        return $process->getExitCode();
    }

    public function composerRemove(string $packageName): int
    {
        $args = ['composer', 'remove', $packageName];
        $process = new Process($args, base_path());
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) { echo $buffer; });
        return $process->getExitCode();
    }

    public function relativePathFromBase(string $absPath): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR);
        $abs = rtrim($absPath, DIRECTORY_SEPARATOR);
        if ($abs === $base) {
            return '.';
        }
        if (str_starts_with($abs, $base)) {
            $rel = ltrim(substr($abs, strlen($base)), DIRECTORY_SEPARATOR);
            return str_replace('\\', '/', $rel);
        }
        return str_replace('\\', '/', $absPath);
    }

    /** Normalize a branch base name, stripping a leading 'dev-' if present; defaults to 'main' if empty. */
    public function normalizeBranchBase(string $branchOrConstraint): string
    {
        $b = trim($branchOrConstraint);
        if ($b === '') { return 'main'; }
        if (str_starts_with($b, 'dev-')) {
            $b = substr($b, 4);
        }
        return $b !== '' ? $b : 'main';
    }

    /** Initialize a git repo at path with the given branch as default. */
    public function initGitRepo(string $path, string $branchBase): void
    {
        if (!is_dir($path)) { return; }
        // If already a git repo, try to ensure branch exists
        if (is_dir($path . DIRECTORY_SEPARATOR . '.git')) {
            $this->ensureGitBranch($path, $branchBase);
            return;
        }
        // Try git init -b <branch>, fallback to init + checkout -b
        $proc = new Process(['git', 'init', '-b', $branchBase], $path);
        $proc->run();
        if (!$proc->isSuccessful()) {
            $proc2 = new Process(['git', 'init'], $path);
            $proc2->run();
            if ($proc2->isSuccessful()) {
                $this->ensureGitBranch($path, $branchBase);
            }
        }
    }

    protected function ensureGitBranch(string $path, string $branch): void
    {
        // Create branch if missing and switch to it
        $check = new Process(['git', 'rev-parse', '--verify', $branch], $path);
        $check->run();
        if (!$check->isSuccessful()) {
            $create = new Process(['git', 'checkout', '-b', $branch], $path);
            $create->run();
        } else {
            (new Process(['git', 'checkout', $branch], $path))->run();
        }
    }

    public function isRequiredInComposer(string $packageName): bool
    {
        return $this->isPackageRequired($packageName) !== null;
    }

    public function removeComposerRepositoryByPath(string $absPath): bool
    {
        $composerFile = base_path('composer.json');
        $composer = $this->getRootComposer();
        $repoPath = $this->relativePathFromBase($absPath);
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
            file_put_contents($composerFile, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        }
        return $removed;
    }
}
