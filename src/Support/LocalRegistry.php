<?php

declare(strict_types=1);

namespace AlexKassel\LaravelPackageEngine\Support;

final class LocalRegistry
{
    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: base_path('storage/app/laravel-package-engine/local-packages.json');
    }

    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $json = json_decode((string) @file_get_contents($this->file), true);
        return is_array($json) ? $json : [];
    }

    public function add(string $packageName, string $absPath): void
    {
        $data = $this->all();
        $data[$packageName] = $this->normalizePath($absPath);
        $this->persist($data);
    }

    public function remove(string $packageName): void
    {
        $data = $this->all();
        unset($data[$packageName]);
        $this->persist($data);
    }

    private function persist(array $data): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private function normalizePath(string $p): string
    {
        return str_replace('\\', '/', $p);
    }
}

