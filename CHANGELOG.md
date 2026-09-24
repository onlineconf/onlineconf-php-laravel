# Changelog

## 1.2.0 — 2026-09-25

The 1.2 line replaces the 1.1 mechanisms with one: the nodes an application reads are declared in
`config/*.php`, next to the values they override.

### Added

- Nodes are named where the value lives: `Onlineconf::getRefString($path, $fallback)` and its siblings
  `getRefInt()`, `getRefFloat()`, `getRefBool()`, `getRefDuration()`, `getRefDurationMs()`,
  `getRefStrings()`, `getRefArray()` and `getRef()` leave a `Ref` marker in the configuration;
  `ConfigOverride::install()` replaces every marker with its fallback and derives the map from them. The type
  is declared by the method, never guessed from the fallback. The names mirror the client's own getters:
  `getString()` reads now, `getRefString()` refers for later.
- `requireRefString($path)` and the rest of the `requireRef*()` family take no fallback: the node must exist,
  the override reads it with the client's `require*`, and the exception reaches the caller.
- The facade works during `LoadConfiguration`, before the service providers: `Onlineconf::getString()` and
  the other getters read the module of the process environment, for config files that transform the value
  (a cast, `explode()`, concatenation). The reads are recorded (`EagerReads`) for the new command.
- `onlineconf:map` command: the derived map (config key, path, type, required, fallback) and the immediate
  reads, as a table or `--json`.

### Changed

- `config('onlineconf.map')` is output, not input: `install()` writes the derived map there for the tooling,
  and a boot from `config:cache` — where the markers are already resolved — installs the override from it.
  A second `install()` on the same configuration changes nothing, the registry of immediate reads included.
- Markers are replaced in the configuration even when there is nothing to override, so `config()` never hands
  a `Ref` object to the application.
- A facade call before the service provider is registered no longer throws "A facade root has not been set":
  it goes to the immediate module resolved from the process environment.
- A module file that cannot be opened is a normal state, not a failure: the module manager remembers the
  failed open by its configured file name for the life of the process (the container under Octane), notes it
  once at `debug` level and rethrows it instead of reopening; `fake()` replaces it. Lazy markers then serve the values from
  `config/*.php` and immediate `get*` return their defaults, silently; `requireRef*()` markers and immediate
  `require*` throw the client's `OpenException`. An application on a machine with no OnlineConf at all runs on
  `config/*.php` alone, as long as it declares no required node, and without a single PHP warning: a file that
  does not exist is reported before the client's `fopen()` is reached.
- An absent node is a silent fallback: the value in `config/*.php` is the default, and the tree holds only
  what must differ from it.

### Removed

- The hand-written `onlineconf.map`: the map is derived from the markers only, and the `map` key is gone from
  the published `config/onlineconf.php`.
- Missing-node reporting: the `on_missing` config key, the `MissingValue` object and the call-site capture.
- The `config_override` config key and the `ONLINECONF_CONFIG_OVERRIDE` variable: the override is always
  installed, and an application without a module reads nothing anyway.

## 1.1.0 — 2026-09-21

- `on_missing` handler: an invokable class or a Closure called once per config key and process when a mapped
  key is absent from OnlineConf, with a `MissingValue` (config key, path, fallback, module, call site).
- `onlineconf:set` command: edits a local module file (rebuilds the CDB and the `.conf` next to it).
- Requires `onlineconf/onlineconf` ^1.1 (`Onlineconf\Cdb` writers and reader).

## 1.0.0

- Initial release: service provider, `ModuleManager`, `Onlineconf` facade with `fake()`,
  `InteractsWithOnlineconf` trait, `onlineconf:get` command, an `artisan about` section and the lazy
  `config()` override (`ConfigOverride`, `Config\OverridingRepository`).
