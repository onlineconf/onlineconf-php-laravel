# Changelog

## 1.1.0 — 2026-09-21

- `on_missing` handler: an invokable class or a Closure called once per config key and process when a mapped
  key is absent from OnlineConf, with a `MissingValue` (config key, path, fallback, module, call site).
- `onlineconf:set` command: edits a local module file (rebuilds the CDB and the `.conf` next to it).
- Requires `onlineconf/onlineconf` ^1.1 (`Onlineconf\Cdb` writers and reader).

## 1.0.0

- Initial release: service provider, `ModuleManager`, `Onlineconf` facade with `fake()`,
  `InteractsWithOnlineconf` trait, `onlineconf:get` command, an `artisan about` section and the lazy
  `config()` override (`ConfigOverride`, `Config\OverridingRepository`).
