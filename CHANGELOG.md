# Changelog

## 1.2.0 — 2026-09-24

- Nodes are named in `config/*.php` instead of the central map: `Onlineconf::refString()`, `refInt()`,
  `refFloat()`, `refBool()`, `refDuration()`, `refDurationMs()`, `refStrings()`, `refArray()` and `ref()`
  leave a `Ref` marker next to the value; `ConfigOverride::install()` replaces every marker with its fallback
  and derives the map from them. The type is declared by the method, never guessed from the fallback.
- An omitted fallback makes the node required: the override reads it with `require*`, so the client's
  exception reaches the caller instead of a silent fallback, and `on_missing` is not called for it.
- The facade works during `LoadConfiguration`, before the service providers: `Onlineconf::getString()` and the
  other getters read the module of the process environment, for config files that transform the value. The
  reads are recorded (`EagerReads`) and the misses are reported through `on_missing` with an empty
  `configKey`. `ONLINECONF_CONFIG_OVERRIDE` switches them off as it switches the override off. A module file
  that cannot be opened is not fatal: `get*` fall back to their defaults and the failure is logged once at
  install, while `require*` throw the client's `OpenException`.
- `onlineconf:map` command: the derived map (config key, path, type, required, fallback) and the immediate
  reads, as a table or `--json`.
- `onlineconf.map` is now derived and written back to the configuration; the 1.1 format (`key => path`) keeps
  working and an entry may also be `['path' => ..., 'type' => ..., 'required' => ...]`.

## 1.1.0 — 2026-09-21

- `on_missing` handler: an invokable class or a Closure called once per config key and process when a mapped
  key is absent from OnlineConf, with a `MissingValue` (config key, path, fallback, module, call site).
- `onlineconf:set` command: edits a local module file (rebuilds the CDB and the `.conf` next to it).
- Requires `onlineconf/onlineconf` ^1.1 (`Onlineconf\Cdb` writers and reader).

## 1.0.0

- Initial release: service provider, `ModuleManager`, `Onlineconf` facade with `fake()`,
  `InteractsWithOnlineconf` trait, `onlineconf:get` command, an `artisan about` section and the lazy
  `config()` override (`ConfigOverride`, `Config\OverridingRepository`).
