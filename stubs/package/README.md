# {{ packageStudly }}

A Laravel package scaffold located at `packages/{{ vendor }}/{{ package }}` in your monorepo.

## Install in your app

Add it as a Composer path repository and require `{{ vendor }}/{{ package }}` in your app.

## Development

- Routes: `routes/web.php`
- Views: `resources/views` (Blade namespace: `{{ package }}::`)
- Config: `config/package.php`
- Service Provider: `src/Providers/PackageServiceProvider.php`
