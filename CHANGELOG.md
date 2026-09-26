# Changelog

## 1.3.1 — 2026-09-26

### Fixed

- `Onlineconf::get*()` after the package's provider has registered threw the client's `OpenException` on a
  machine without a module file, instead of returning its default as it does before boot. This broke
  `php artisan config:cache` without a module (an initContainer before the volume is mounted) for any
  config file with an immediate read: the command loads the configuration of a second application while the
  facade still belongs to the first, booted one. The facade now follows one absence rule on both paths:
  `get*()` give their default, `require*()` and the methods that are not reads throw.

## 1.3.0 — 2026-09-25

### Added

- Post-processing for lazy markers: `getRef*($path, $fallback, $transform)` and `requireRef*($path, $transform)`
  take an optional callable. The declared type stays the type of the node; the transform gets the typed value —
  the node's, or the fallback — and its result is what `config()` returns. `ConfigOverride::install()` writes the
  transformed fallback into the configuration, so `config()` without a module, `config()->all()` and
  `config:cache` have the final shape; the derived map keeps the raw fallback and the stored transform.
- The transformed node value is memoised per config key until the module version changes.
- A transform may be a `Closure` (stored unsigned with `laravel/serializable-closure`, now a direct
  dependency; a user static method's `Class::method(...)` included), a function name or a `[class, static
  method]` pair. An invokable object, an object method (`[$obj, 'm']`, `$obj->m(...)`) and a first-class
  callable of a function or of a PHP class's static method are an `InvalidArgumentException` naming the path
  and the storable form, when the marker is built. Closures survive `config:cache` whatever `APP_KEY` is set,
  now or later, and whatever their source raises when it is compiled again.
- A transform that throws propagates as a `RuntimeException` naming the config key and the path. The memo is
  shared between the clones Octane makes of the configuration repository per request.
- `onlineconf:map` has a `Transform` column (`transform: bool` in `--json`) and shows the fallback as written.
- `Ref::value()`: a marker reads its node on demand — the facade's immediate read of its type, with the
  absence rules of `config()`, then its transform. For code outside `config/*.php`; a read after boot is not
  listed by `onlineconf:map`, one while the configuration loads is among its immediate reads, with the marker's
  own required flag. A held marker keeps its decoded transform, and with it a closure's `static` variables and
  mutable `use` objects, between calls: keep transforms pure.
- `onlineconf:map` shows whether an immediate read was a `require*` (`Required` column, `required` in `--json`).

### Upgrading and rolling back

- A configuration cache written by 1.2 keeps working: its map entries carry no fallback, which is then taken
  from the cached configuration.
- Rolling back to 1.2 needs `php artisan config:cache` again: a 1.3 cache holds the transformed fallbacks.

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
