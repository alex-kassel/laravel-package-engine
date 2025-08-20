# Laravel Package Engine

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E10.0%7C%5E11.0%7C%5E12.0-red.svg)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Ein Laravel-Helfer zur einfachen Erstellung und Verwaltung lokaler Packages unter dem konfigurierbaren `/{packages_path}` (Standard: `/packages`). Composer Path-Repositories und Symlinks/Junctions werden automatisch gehandhabt.

## Features
- Pakete per Befehl erstellen: `packages:make`
- Pakete installieren/entfernen: `packages:install`, `packages:uninstall`, `packages:remove`
- Pakete neu installieren: `packages:reinstall`
- Pakete neu erstellen (Verzeichnis löschen + neu aus Stubs bauen): `packages:remake`
- Composer Path-Repositories und Symlinks automatisch einrichten
- Vollständige Paket-Struktur via Stubs (Config, Routes, Views, Provider, Tests)
- Fügt `/{packages_path}` automatisch zu `.gitignore` hinzu, wenn der Ordner angelegt wird

## Installation

1) Als lokales Paket in Ihr Projekt aufnehmen (empfohlen als Path-Repository):

```json
{
  "repositories": [
    { "type": "path", "url": "packages/alex-kassel/laravel-package-engine", "options": { "symlink": true } }
  ]
}
```

```bash
composer require alex-kassel/laravel-package-engine:dev-main
```

2) Optional: Config & Stubs veröffentlichen und anpassen

```bash
php artisan vendor:publish --provider="AlexKassel\\LaravelPackageEngine\\LaravelPackageEngineServiceProvider" --tag=laravel-package-engine-stubs
php artisan vendor:publish --provider="AlexKassel\\LaravelPackageEngine\\LaravelPackageEngineServiceProvider" --tag=laravel-package-engine-config
```

Die Engine-Config wird nach `config/alex-kassel/laravel-package-engine/config.php` veröffentlicht. Die Package-Stubs können unter `stubs/alex-kassel/laravel-package-engine` angepasst werden.

## Verwendung

Neue Package-Struktur anlegen:

```bash
php artisan packages:make vendor/package-name
php artisan packages:make vendor/package-name vendor/package2 --install
php artisan packages:make vendor/package-name --install --dev --alias=short-name
# Branch/Version angeben (override, Default siehe Config)
php artisan packages:make vendor/package-name --install --branch=dev-main
```

Lokale Pakete installieren/entfernen:

```bash
php artisan packages:install vendor/package-name
php artisan packages:install vendor/package-name vendor/package2 --dev
php artisan packages:install --all
php artisan packages:uninstall vendor/package-name
php artisan packages:remove vendor/package-name --delete-dir
php artisan packages:reinstall vendor/package-name --dev
php artisan packages:remake vendor/package-name --install --dev --alias=short
```

### Hinweise zu Composer-Versionen

- Standard-Branch (Default): `dev-master` – konfigurierbar in `config/laravel-package-engine.php` via `default_branch`.
- Mit `--branch` kann pro Befehl übersteuert werden (z. B. `--branch=dev-main`).

### Windows: Symlink/Junction

- Auf Windows wird automatisch auf eine Directory-Junction (`mklink /J`) zurückgefallen, wenn `symlink()` nicht erlaubt ist.
- Falls die Erstellung fehlschlägt, führen Sie den Befehl als Administrator aus oder erstellen Sie den Link manuell.

## Stubs und Config

- Stubs veröffentlichen (angepasst werden unter `stubs/alex-kassel/laravel-package-engine`):

```bash
php artisan vendor:publish --tag=laravel-package-engine-stubs
```

- Engine-Config veröffentlichen (`config/alex-kassel/laravel-package-engine/config.php`):

```bash
php artisan vendor:publish --tag=laravel-package-engine-config
```

Die generierten Package-Provider veröffentlichen ihre Package-Config nach `config/vendor/name/config.php`.

## Ordnerstruktur

```
packages/
└── vendor/
    └── package-name/
        ├── config/
        ├── database/migrations/
        ├── resources/views/
        ├── routes/
        ├── src/Http/Controllers/
        ├── src/Models/
        ├── src/Providers/
        ├── tests/Feature/
        ├── composer.json
        ├── LICENSE
        └── README.md
```

## Lizenz
MIT – siehe LICENSE

## Best Practices / Tipps

- Trennen Sie Anwendungslogik in wiederverwendbare Packages.
- Pinnen Sie Abhängigkeiten in den Package-`composer.json` Dateien klar (SemVer), lokal kann `dev-*` genutzt werden.
- Nutzen Sie die veröffentlichten Stubs, um Ihre Standardstruktur projektweit anzupassen (`vendor:publish --tag=laravel-package-engine-stubs`).
- Passen Sie `packages_path` in der Config an, wenn Ihre lokalen Pakete nicht unter `/packages` liegen sollen.
- Dokumentieren Sie jedes Package minimal mit einem README und Beispielen (Routes, Config-Beispiele, View-Namespace).
