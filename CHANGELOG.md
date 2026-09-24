# Changelog

## 1.2.0 — 2026-09-24

- Nodes are named in `config/*.php` instead of the central map: `Onlineconf::getRefString($path, $fallback)`
  and its siblings `getRefInt()`, `getRefFloat()`, `getRefBool()`, `getRefDuration()`, `getRefDurationMs()`,
  `getRefStrings()`, `getRefArray()` and `getRef()` leave a `Ref` marker next to the value;
  `ConfigOverride::install()` replaces every marker with its fallback and derives the map from them. The type
  is declared by the method, never guessed from the fallback. The names mirror the client's own getters:
  `getString()` reads now, `getRefString()` refers for later.
- `requireRefString($path)` and the rest of the `requireRef*()` family take no fallback: the node must exist,
  the override reads it with the client's `require*`, so the exception reaches the caller instead of a silent
  fallback, and `on_missing` is not called for it.
- The facade works during `LoadConfiguration`, before the service providers: `Onlineconf::getString()` and the
  other getters read the module of the process environment, for config files that transform the value. The
  reads are recorded (`EagerReads`) and the misses are reported through `on_missing` with an empty
  `configKey`. `ONLINECONF_CONFIG_OVERRIDE` switches them off as it switches the override off. A module file
  that cannot be opened is not fatal: `get*` fall back to their defaults and the failure is logged once at
  install, while `require*` throw the client's `OpenException`.
- `onlineconf:map` command: the derived map (config key, path, type, required, fallback) and the immediate
  reads, as a table or `--json`.
- `onlineconf.map` is now derived and written back to the configuration; the 1.1 format (`key => path`) keeps
  working and an entry may also be `['path' => ..., 'type' => ..., 'required' => ...]`. An entry that declares
  a type that does not exist is a `LogicException` at install, not a silently ignored one.

### Changed

- `config('onlineconf.map')` after the install is always the normalised
  `['path' => ..., 'type' => ...|null, 'required' => bool]` shape, with the kill switch off as well.
- `ConfigOverride::install()` replaces the markers in the configuration even when the override is disabled,
  so `config()` never hands a `Ref` object to the application; with the kill switch off, required markers
  leave `null` behind and one warning names them.
- A facade call before the service provider is registered no longer throws "A facade root has not been set":
  it goes to the immediate module resolved from the process environment.

## 1.1.0 — 2026-09-21

- `on_missing` handler: an invokable class or a Closure called once per config key and process when a mapped
  key is absent from OnlineConf, with a `MissingValue` (config key, path, fallback, module, call site).
- `onlineconf:set` command: edits a local module file (rebuilds the CDB and the `.conf` next to it).
- Requires `onlineconf/onlineconf` ^1.1 (`Onlineconf\Cdb` writers and reader).

## 1.0.0

- Initial release: service provider, `ModuleManager`, `Onlineconf` facade with `fake()`,
  `InteractsWithOnlineconf` trait, `onlineconf:get` command, an `artisan about` section and the lazy
  `config()` override (`ConfigOverride`, `Config\OverridingRepository`).
