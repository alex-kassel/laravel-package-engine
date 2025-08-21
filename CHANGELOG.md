# Changelog
All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and this project adheres to Semantic Versioning.

## [Unreleased]
- packages:make
  - Add aliases: `packages:create`, `packages:new`.
  - Add `--path=` option (takes precedence over config `packages_path`).
  - Immediately add Composer path repository entry for the created package.
  - Initialize a git repository in the new package and set default branch from `--branch` or config (strip leading `dev-`).
  - Warn if the same `vendor/package` is already present in composer.json (`require`/`require-dev`) or resolvable via configured path repositories (including globbed URLs); proceed but inform.
- packages:install
  - Use `composer require` (no direct composer.json edits) and let Composer handle linking for path repositories.
  - Warn about conflicts if the package is already required or present via a path repository.
- packages:uninstall / packages:remove
  - Call `composer remove` only if the package is actually required.
  - With `--all`, only target packages created by this engine (tracked in a local registry file); third-party path repos are not touched.
  - Remove deletes the path repository entry and optionally deletes the package directory (omit with `--keep-dir`).
- packages:remake / packages:reinstall
  - Remake delegates to `packages:remove` + `packages:make` and forwards options (e.g., `--path`). Uses registry for `--all`.
  - Reinstall delegates to `packages:uninstall` + `packages:install`. Uses registry for `--all`.
- Path repositories
  - `ensureComposerRepository` respects globbed `url` patterns in composer.json and avoids adding redundant entries.
- Registry
  - Introduce a local registry at `storage/app/laravel-package-engine/local-packages.json` to track packages created via `packages:make`.
- Docs
  - README and comments updated to English and reflect the new behavior (conflict warnings, registry-based `--all`, Composer-managed linking).
