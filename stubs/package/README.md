# {{ packageStudly }}

Ein Laravel-Paket innerhalb Ihres Monorepos unter `packages/{{ vendor }}/{{ package }}`.

## Installation (im App-Repo)

- Via Composer Path-Repository eingebunden und `{{ vendor }}/{{ package }}` als Abhängigkeit aufnehmen.

## Entwicklung

- Routen: `routes/web.php`
- Views: `resources/views`
- Konfiguration: `config/package.php`
- Service Provider: `src/Providers/PackageServiceProvider.php`
