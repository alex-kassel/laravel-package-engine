# Laravel Package Engine

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/laravel-%5E10.0%7C%5E11.0%7C%5E12.0-red.svg)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A lightweight helper to create and manage local Laravel packages under a configurable `/{packages_path}` (default: `/packages`). It manages Composer path repositories, initialization of new package repos, and installs via Composer.

## Features
- Create packages from stubs: `packages:make` (aliases: `packages:create`, `packages:new`)
- Install/uninstall/remove: `packages:install`, `packages:uninstall`, `packages:remove`
- Reinstall/remake: `packages:reinstall`, `packages:remake`
- Composer path repository is added during Make (before install)
- Install uses `composer require`; Composer handles linking for path repositories
- Uninstall/Remove use `composer remove` only if needed
- Make initializes a git repository in the new package and sets the default branch
- Scans multiple roots: supports any `packages*` directories (e.g., `packages/`, `packages2/`)
- Adds `/{packages_path}` to `.gitignore` when first created

## Installation

Add this engine as a local path repository in your app and require it:

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

Optional: publish config & stubs to customize defaults:

```bash
php artisan vendor:publish --provider="AlexKassel\\LaravelPackageEngine\\LaravelPackageEngineServiceProvider" --tag=laravel-package-engine-stubs
php artisan vendor:publish --provider="AlexKassel\\LaravelPackageEngine\\LaravelPackageEngineServiceProvider" --tag=laravel-package-engine-config
```

Config is published to `config/alex-kassel/laravel-package-engine/config.php`. Stubs live under `stubs/alex-kassel/laravel-package-engine`.

## Usage

Create new packages:

```bash
php artisan packages:make vendor/package-name
php artisan packages:make vendor/package-a vendor/package-b --install
php artisan packages:make vendor/package-name --install --branch=dev-main
# Choose a custom root for local packages (takes precedence over config)
php artisan packages:make vendor/package-name --path=custom/packages
```

Notes:
- If a package with the same `vendor/package` already exists in any configured path repository (including globbed entries) or is already listed in `require`/`require-dev`, the engine emits clear warnings showing exactly where the conflict is (section or path). Creation/installation proceeds, but you are informed.
- The `--path` option takes precedence over the configured `packages_path`.
- During Make, a git repository is initialized inside the new package; the default branch is derived from `--branch` or configuration by stripping the leading `dev-` (e.g., `dev-main` -> `main`).

Install/uninstall/remove local packages:

```bash
php artisan packages:install vendor/package-name
php artisan packages:install vendor/package-name vendor/package2 --dev
php artisan packages:install --all
php artisan packages:uninstall vendor/package-name
php artisan packages:remove vendor/package-name
php artisan packages:reinstall vendor/package-name --dev
php artisan packages:remake vendor/package-name --install --path=custom/packages
```

### Composer behavior
- Make adds a Composer path repository entry for the new package immediately.
- Install runs `composer require vendor/package:dev-<branch>`; the base branch is derived from `--branch` or `default_branch` in config (e.g., `dev-main`).
- Uninstall and Remove run `composer remove` only if the dependency exists in `require` or `require-dev`.
- Linking is handled by Composer for path repositories; the package itself does not create symlinks.
- Remove also deletes the path repository entry; it deletes the package directory unless `--keep-dir` is used.
- For `--all` with uninstall/remove, only packages created by this engine (tracked in a local registry file under `storage/app/laravel-package-engine/local-packages.json`) are targeted; third-party/local path repos not created via `packages:make` are left untouched.

## Stubs and config

Publish stubs and edit them to fit your defaults:

```bash
php artisan vendor:publish --tag=laravel-package-engine-stubs
```

Publish and tweak the engine config (`config/alex-kassel/laravel-package-engine/config.php`):

```bash
php artisan vendor:publish --tag=laravel-package-engine-config
```

## Directory layout

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

## License
MIT – see LICENSE

## Best Practices / Tips

- Split application logic into reusable packages.
- Pin dependencies clearly in the package `composer.json` files (SemVer), use `dev-*` locally if needed.
- Use the published stubs to customize your default structure across the project (`vendor:publish --tag=laravel-package-engine-stubs`).
- Adjust `packages_path` in the config if your local packages should not be under `/packages`.
- Document each package minimally with a README and examples (routes, config examples, view namespace).
