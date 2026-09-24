# onlineconf-laravel

Laravel integration for [onlineconf-php](https://github.com/onlineconf/onlineconf-php), the PHP client of
[OnlineConf](https://github.com/onlineconf/onlineconf). Container bindings, a facade, a config file, test
helpers and artisan commands — and nothing else: every getter, default, exception and reload rule is the
client's, documented in its README.

- Laravel 10, 11 and 12; PHP ≥ 8.1; `ext-dba` with the `cdb` handler (see the client README for installation).
- No changes to value semantics, no extra caching layer, no application-specific behaviour.

## Installation

```sh
composer require onlineconf/onlineconf-laravel
php artisan vendor:publish --tag=onlineconf-config   # optional: config/onlineconf.php
```

The service provider and the `Onlineconf` facade alias are auto-discovered.

### Try it without an updater

The client ships ready-made modules in `vendor/onlineconf/onlineconf/examples/onlineconf/`: `TREE.cdb`
(strings, numbers, booleans, durations, JSON arrays, child lists) and `legacy.cdb` (dot-notation keys).
Next to each `.cdb` lies a `.conf` with the same content in the updater's text format — a human-readable
listing only, the library never reads it. Point the package at that directory and look around:

```sh
export ONLINECONF_DIR=$PWD/vendor/onlineconf/onlineconf/examples/onlineconf
export ONLINECONF_CONFIG_OVERRIDE=true                           # needed for the config() override below
php artisan onlineconf:get /app/hosts/main                       # www.example.com
php artisan onlineconf:get --tree /app/nginx/anti-ddos           # pretty JSON of the subtree
php artisan onlineconf:get --json /app/services/billing/admin_emails | jq .
php artisan about --only=onlineconf                              # directory, module file, version
```

The same tree works for the `config()` override below. With `ConfigOverride::register($app)` installed
and this map in `config/onlineconf.php`:

```php
'map' => [
    'app.url'          => '/app/hosts/main',                 // string fallback → "www.example.com"
    'session.lifetime' => '/app/nginx/anti-ddos/cookie-ttl', // int fallback    → 7200
    'app.debug'        => '/app/nginx/anti-ddos/debug',      // bool fallback   → false ("0")
],
```

`config('session.lifetime')` returns the integer `7200` and `config('app.debug')` returns `false`, while
every unmapped key keeps its value from `config/*.php`. Without `ONLINECONF_CONFIG_OVERRIDE` nothing is read
from OnlineConf and every key keeps its configured value. Unset both variables (or set them in `.env`) when
you are done.

## Configuration

`config/onlineconf.php`:

| key | env | default | meaning |
|---|---|---|---|
| `dir` | `ONLINECONF_DIR` | `null` | directory with module files; `null` lets the client resolve it |
| `module` | `ONLINECONF_MODULE` | `null` | default module: a name (`TREE`) or a file path; `null` = client default |
| `check_interval` | `ONLINECONF_CHECK_INTERVAL` | `5` | seconds between `stat()` checks for updates, `0` = every access |
| `log_channel` | `ONLINECONF_LOG_CHANNEL` | `null` | log channel for the client's warnings; `null` = default logger |
| `config_override` | `ONLINECONF_CONFIG_OVERRIDE` | `false` | switch of the `config()` override and of the immediate reads below; unset means off |
| `on_missing` | — | `null` | handler for mapped keys absent from OnlineConf: a class name (resolved from the container, invoked with a `MissingValue`) or a Closure; see below |
| `map` | — | `[]` | extra Laravel config key → OnlineConf node; the map the override uses is **derived** from the `getRef*()` markers in `config/*.php` and merged with this one (see below) |

With `dir` and `module` unset the client's own resolution applies: `ONLINECONF_DIR`, `ONLINECONF_CONFIG`
and `CDB_CONFIG_FILE` from the **process environment**, then `/usr/local/etc/onlineconf.yaml`, then
`/usr/local/etc/onlineconf` and `TREE`. Laravel's Dotenv keeps `putenv()` enabled by default, so values from
`.env` do reach `getenv()` and the client's own resolution sees them. Put `ONLINECONF_DIR` (and
`ONLINECONF_MODULE` if needed) into `.env`; the two config keys above are the explicit alternative and win
over the environment. `config:cache` is safe: `env()` is read only inside the config file.

Two setups do not export `.env` to `getenv()`: an application that calls `Env::disablePutenv()` (Testbench
does while it builds the test application) and a `config:cache`d process, where `.env` is not read at all.
The config keys keep working there, but the **immediate reads** of the next section resolve their directory
from `getenv()` alone — give those processes a real `ONLINECONF_DIR` in the environment.

The manager snapshots `dir`, `module`, `check_interval` and `log_channel` when it is first resolved (the first
`Module` injection, facade call, or `ConfigOverride` install) — change them in `config/onlineconf.php` or
`.env`, not at runtime. When the `config()` override is active, `log_channel` is resolved before service
providers register, so a channel whose driver comes from `Log::extend()` in a provider cannot be used there
(built-in drivers and `'driver' => 'custom'` work).

## Usage

Inject the client's `Module` anywhere:

```php
use Onlineconf\Module;

final class Mailer
{
    public function __construct(private readonly Module $config) {}

    public function send(): void
    {
        $host = $this->config->getString('/my/service/smtp/host', 'localhost');
        $timeout = $this->config->getDurationMs('/my/service/smtp/timeout', 5000);
    }
}
```

Or use the facade:

```php
use Onlineconf\Laravel\Facades\Onlineconf;

Onlineconf::getInt('/my/service/db/port', 3306);
Onlineconf::requireStrings('/my/service/hosts');
Onlineconf::subtree('/my/service')->getBool('/enabled', false);
Onlineconf::module('other')->getString('/key', '');      // another module: <dir>/other.cdb or a file path
```

The facade proxies to the default module; its short name coincides with the client's static registry
`Onlineconf\Onlineconf`, which this package does not use — import the facade, not the registry.

Paths are always full paths. There is no application prefix; use `subtree()` where a prefix helps.

### Runtime behaviour

- **PHP-FPM**: one `Module` per request, `dba_open` on the first read and one `stat()` per request.
- **Octane, Horizon, queue workers, long-running commands**: the same `Module` lives as long as the
  process and reloads itself when the file changes (`check_interval`). Code that caches values derived
  from the configuration should compare `Onlineconf::version()` or call `Onlineconf::checkForUpdates()`
  itself; the package adds no hooks.
- Opening the module file happens on the first use (first injection of `Module`, first facade call),
  not at boot. A missing or invalid file throws the client's `OpenException` at that point.

## Testing your application

```php
use Onlineconf\Laravel\Facades\Onlineconf;

$source = Onlineconf::fake([
    '/my/service/db/host' => 'db.local',      // string → s value
    '/my/service/db/opts' => ['pool' => 5],   // array  → j value (JSON)
    '/my/service/flag'    => null,            // null   → empty s value
]);
// call fake() before resolving the service under test — a singleton that already received a Module
// keeps the old instance

$source->replaceValues(['/my/service/db/host' => 'other']);   // visible on the next read
Onlineconf::fake(['/key' => 'value'], 'other');                // a named module
```

The same as a trait method for test cases:

```php
use Onlineconf\Laravel\Testing\InteractsWithOnlineconf;

final class MailerTest extends TestCase
{
    use InteractsWithOnlineconf;

    public function testSend(): void
    {
        $this->fakeOnlineconf(['/my/service/smtp/host' => 'smtp.example.com']);
        // ...
    }
}
```

Fakes need no module files; child lists are generated, so `children()` and `getTree()` work on them. A fake
also feeds the `config()` override below, so `config('services.mailer.host')` returns the faked value.

## Overriding config() values

To migrate settings from `.env` to OnlineConf without touching the code that calls `config()`, map config
keys to OnlineConf paths and install the override in `bootstrap/app.php`.

Laravel 10:

```php
$app = new Illuminate\Foundation\Application(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);

// ...

\Onlineconf\Laravel\ConfigOverride::register($app);

return $app;
```

Laravel 11 and 12:

```php
$app = Application::configure(basePath: dirname(__DIR__))
    // ->withRouting(...)
    // ->withMiddleware(...)
    // ->withExceptions(...)
    ->create();

\Onlineconf\Laravel\ConfigOverride::register($app);

return $app;
```

and name the nodes in `config/*.php` (see the next section) or in the published `config/onlineconf.php`:

```php
'map' => [
    'services.mailer.host' => '/my/service/mailer/host',
    'services.mailer.port' => '/my/service/mailer/port',
    'app.debug'            => '/my/service/debug',
],
```

From then on `config('services.mailer.host')` is read from OnlineConf. The value from `config/*.php` (usually
`env(...)`) stays as the fallback and is returned whenever: there is no such key in OnlineConf, the value does
not parse (a warning is logged, as the client always does), the value is invalid JSON (an error is logged), or
the module file cannot be opened (an error is logged once). In this map format the OnlineConf value is read
with the type of the fallback: a `bool` in the config file means `getBool`, an `int` means `getInt`, an array
means `getArray` (a JSON value in OnlineConf), a string means `getString`, `null` means the raw value. Reading
`config('services')` as a whole includes the mapped keys under it; an explicit `config()->set()` at runtime
wins over the map.

- The override reads OnlineConf only where `ONLINECONF_CONFIG_OVERRIDE` is set to a truthy value; unset — the
  state of an application that has not enabled it yet — means off, and so does `ONLINECONF_CONFIG_OVERRIDE=false`
  in `.env`. Turn it on per environment, then migrate one key at a time.
- The explicit map lives in the application's published `config/onlineconf.php`: at the moment the override is
  installed, package defaults are not merged yet, so an unpublished config means no explicit entries. Markers
  in `config/*.php` (next section) need no published config file.
- After the install, `config('onlineconf.map')` holds the normalised, derived map — what `onlineconf:map`
  prints. An entry is `['path' => ..., 'type' => ...|null, 'required' => bool]`; `type` is `null` for the
  `key => path` format above, which keeps reading by the type of the fallback.
- `config:cache` is supported: the override works on top of the cached array.
- Reads are lazy, so long-running workers see a changed value on the next `config()` call. Services that read
  their settings once in a constructor keep them, as with any Laravel configuration.
- `set()` at runtime unmaps the written key, everything below it and everything above it: an explicit write
  wins over the map for good, not just until the next read.
- The client logs an unparsable mapped value verbatim at `warning` level (e.g. `"abc" is not an integer`), so
  a malformed secret can end up in the log.
- Not covered: `env()` calls outside `config/*.php` and `getenv()`; move them into a config file first. Also
  not covered: `config()->all()` — it returns the loaded configuration without substitution (this is what
  keeps `config:cache` safe), so code or packages reading `all()` see the fallbacks.
- Cost: an unmapped key costs one extra array lookup; a mapped key is one `dba_fetch` on first read per process,
  then the client's cache.

## Naming nodes in config/*.php

Instead of the central map, a node can be named where the value lives. Two mechanisms, one selection rule:

- **`getRef*()` / `requireRef*()` — a lazy reference.** The value stays in the configuration as a marker;
  `ConfigOverride::install()` replaces it with the fallback and adds it to the map, so every `config()` call
  reads OnlineConf. Use it when the value is used **as is**.
- **`get*()` / `require*()` — an immediate read.** The facade reads OnlineConf right there, while
  `config/*.php` is being loaded, and the plain value lands in the configuration. Use it when the config file
  **transforms** the value: a cast, `explode()`, string concatenation, a condition.

The names mirror each other exactly: `getString()` reads a string now, `getRefString()` refers to it for
later; `requireString()` reads a node that must exist now, `requireRefString()` refers to one for later. The
`getRef*()` family always takes a fallback (`null` is a fallback), the `requireRef*()` family never does.

```php
// config/services.php
use Onlineconf\Laravel\Facades\Onlineconf;

return [
    'mailer' => [
        // lazy: config('services.mailer.host') reads OnlineConf on every call
        'host'    => Onlineconf::getRefString('/my/service/mailer/host', env('MAIL_HOST')),
        'port'    => Onlineconf::getRefInt('/my/service/mailer/port', 25),
        'timeout' => Onlineconf::getRefDuration('/my/service/mailer/timeout', 5.0),
        'secret'  => Onlineconf::requireRefString('/my/service/mailer/secret'), // no fallback: the node must exist

        // immediate: the value is transformed here, so it cannot stay a marker
        'endpoint' => 'https://' . Onlineconf::getString('/my/service/mailer/host', 'localhost') . '/send',
    ],
];
```

| declared type | lazy, with a fallback | lazy, node required | immediate | OnlineConf value |
|---|---|---|---|---|
| `string` | `getRefString($p, $f)` | `requireRefString($p)` | `getString()` / `requireString()` | the `s` value as is |
| `int` | `getRefInt($p, $f)` | `requireRefInt($p)` | `getInt()` / `requireInt()` | an integer |
| `float` | `getRefFloat($p, $f)` | `requireRefFloat($p)` | `getFloat()` / `requireFloat()` | a float |
| `bool` | `getRefBool($p, $f)` | `requireRefBool($p)` | `getBool()` / `requireBool()` | `1`/`0`, `true`/`false`, `yes`/`no` |
| `duration` | `getRefDuration($p, $f)` | `requireRefDuration($p)` | `getDuration()` / `requireDuration()` | `30s`, `1m` → seconds as a float |
| `duration_ms` | `getRefDurationMs($p, $f)` | `requireRefDurationMs($p)` | `getDurationMs()` / `requireDurationMs()` | the same → milliseconds as an int |
| `strings` | `getRefStrings($p, $f)` | `requireRefStrings($p)` | `getStrings()` / `requireStrings()` | `a, b` or a JSON array of strings |
| `array` | `getRefArray($p, $f)` | `requireRefArray($p)` | `getArray()` / `requireArray()` | a `j` value decoded to an array |
| `raw` | `getRef($p, $f)` | `requireRef($p)` | `get()` / `require()` | `s` → string, `j` → decoded JSON |

The type is what the method declares; it is never guessed from the fallback. `getRefBool('/my/app/debug',
(bool) env('APP_DEBUG'))` — the cast keeps the fallback the type its node is.

**A marker is not a value.** `(bool) Onlineconf::getRefBool(...)` is `true` for every marker and `(int)` is `1`,
because the cast sees an object, not the node — which is exactly why a cast in the config file means `get*`.
Using a marker as a string throws a `LogicException` naming the path instead of failing quietly.

**Markers do not belong in `config/onlineconf.php`, `app.env` or `app.timezone`.** The package's own settings
(`dir`, `module`, `log_channel`, `check_interval`, `config_override`) and the keys `LoadConfiguration` itself
consumes (`app.env`, `app.timezone`) are read before the markers are resolved, so a marker there would be
read as an object. An immediate `get*` works in those files, with the caveat that its own directory comes
from the environment.

**Required nodes.** `requireRefString('/my/app/secret')` and its siblings take no fallback: the node must
exist, and the override calls the client's `require*`, so `NotFoundException` (or `FormatException`,
`ParseException`) reaches the caller instead of a silent fallback. `getRefString('/my/app/secret', null)` is
the other case — a node that may be absent, with `null` as its fallback. A required node that is missing is a
configuration error, not a migration gap, so the `on_missing` handler is not called for it. Only a module
file that cannot be opened is still a fallback: it is an availability failure, logged once, and the
configuration value is returned.

The exception also surfaces on an ancestor read: `config('database')` reads every mapped key below it, so a
missing required node throws there as well, not only on `config('database.connections.mysql.password')`.

With `ONLINECONF_CONFIG_OVERRIDE` unset or false nothing is read, so a required marker leaves `null` in the
configuration. `ConfigOverride::install()` logs one warning naming those keys —
`OnlineConf override is disabled; required nodes fall back to null: app.secret, …` — so the `null` is not
silent. Immediate `require*` calls throw `NotFoundException` under the same switch. That warning resolves
`log_channel` at install time, before service providers register, so the caveat above applies here too: a
channel whose driver is registered by `Log::extend()` in a provider cannot receive it.

When the node exists but does not parse as the declared type, the value from `config/*.php` is returned as it
is — including `null` — and the client's warning is logged. The fallback is never replaced by an empty value
of the declared type.

### What immediate reads cost

`get*()` in `config/*.php` runs before the service providers, so the facade cannot use the container:

- **The value is fixed for the life of the process** and is baked into `config:cache`. A node that changes
  while a worker runs is not picked up — use `getRef*()` for anything that should follow the tree.
- **The module directory comes from the process environment** (`ONLINECONF_DIR`, `ONLINECONF_CONFIG`,
  `CDB_CONFIG_FILE`, then the client's defaults), not from `config/onlineconf.php`, which is not loaded yet.
  `.env` is already loaded at that point, so `ONLINECONF_DIR` in `.env` works; `onlineconf.dir` does not.
- **The same switch governs immediate reads**: with `ONLINECONF_CONFIG_OVERRIDE` unset or false, `get*`
  return their defaults and `require*` throw `NotFoundException`. Both mechanisms read one variable, so they
  can never disagree about whether this process talks to OnlineConf.
- **A module file that cannot be opened does not stop the boot**: `get*` return their defaults, exactly as
  `config()` does on the lazy path, and `ConfigOverride::install()` logs the failure once through the
  configured log channel. `require*` still throw the client's `OpenException` — a required node cannot be
  satisfied without the tree. So a pod without the OnlineConf volume, or a checkout before the first clone,
  boots with the override flag on.
- Immediate reads are recorded; `ConfigOverride::install()` reports the ones that found nothing to the
  `on_missing` handler with an empty `configKey` (there is no config key, only a call site). Reads that fell
  back because the module could not be opened are not reported there: that is an availability failure, and it
  is logged instead.

### Seeing what is referenced

```sh
php artisan onlineconf:map           # config key | path | type | required | fallback, then the immediate reads
php artisan onlineconf:map --json    # the same as JSON, for dumping values or diffing against the tree
```

The command reports what the current process loaded: it needs `ConfigOverride::register($app)` in
`bootstrap/app.php` to see the markers at all, and under `config:cache` it lists no immediate reads, because
the config files did not run — their values came from the cache.

### Knowing when the fallback is used

A mapped key that OnlineConf does not have is a migration gap: the value still comes from `config/*.php`, but
nobody is told. Set `on_missing` to an invokable class and the package calls it once per config key and process
(per request under PHP-FPM) with an `Onlineconf\Laravel\MissingValue`. Under Octane, Horizon or queue workers,
"once per process" means once per worker lifetime, so a log-based alert may fire only once until the worker
restarts:

```php
// config/onlineconf.php
'on_missing' => App\Onlineconf\ReportMissingValue::class,

// app/Onlineconf/ReportMissingValue.php
final class ReportMissingValue
{
    public function __invoke(\Onlineconf\Laravel\MissingValue $missing): void
    {
        Log::warning('OnlineConf has no value, config() falls back', [
            'config_key' => $missing->configKey, // 'services.mailer.host'
            'path' => $missing->path,            // '/my/service/mailer/host'
            'module' => $missing->module,        // 'TREE'
            'called_at' => $missing->file . ':' . $missing->line, // first frame outside vendor/
            'trace' => $missing->trace,          // up to five such frames
        ]);
    }
}
```

`$missing->fallback` carries the value config() returned; log it only if you know it is not a secret. The
package itself logs nothing here and never swallows an exception thrown by the handler. A module file that
cannot be opened is a different failure (logged once as an error) and does not reach the handler. A Closure is
accepted as well, but a Closure cannot be `config:cache`d. A class name is resolved from the container on each
report (once per config key and process), so keep the handler cheap to construct or bind it as a singleton.

## Artisan

```
php artisan onlineconf:get /my/service/db/host
php artisan onlineconf:get --json /my/service/db/opts | jq .
php artisan onlineconf:get --tree /my/service
php artisan onlineconf:get --module=other /key
php artisan onlineconf:map
php artisan onlineconf:map --json
php artisan about --only=onlineconf

php artisan onlineconf:set /my/service/db/host db.local        # s value
php artisan onlineconf:set --json /my/service/db/opts '{"pool":5}'
php artisan onlineconf:set --delete /my/service/db/opts
php artisan onlineconf:set --module=other /key value
```

`onlineconf:get` prints `s` values as is and `j` values as the stored JSON text; `--json` encodes any
value as JSON, `--tree` prints `getTree()` as pretty JSON (on a path with no descendants this is the
client's `getTree()` behaviour: `null`, exit `0`, not a "not found" error). Exit codes: `0`; `1` when the
key does not exist; `2` on file, format or invalid-JSON errors. `about` shows the directory, the default
module file and the version of the loaded data.

`onlineconf:map` lists every node the configuration refers to — the derived map and the reads that happened
while `config/*.php` was loading — and prints fallbacks as they are, so run it where seeing secrets is fine.

`onlineconf:set` edits a **local** module file: it reads the whole CDB, changes one key, regenerates the child
lists and rewrites both the `.cdb` (atomically, through a temporary file) and the `.conf` listing next to it.
The two writes are not one transaction: if the `.conf` cannot be written the `.cdb` is already updated (the
library never reads `.conf`, so nothing breaks). A value passed together with `--delete` is ignored.
Exit codes: `0`; `1` when `--delete` names a key that does not exist; `2` when the file cannot be opened, the
directory is not writable, the JSON is invalid or the arguments are wrong. It is a development tool for a copy
of a module taken from a real environment; production modules are written by `onlineconf-updater` only.

## Compatibility

The package uses only framework APIs that are identical in Laravel 10, 11 and 12 (`singletonIf`, `bind`,
`mergeConfigFrom`, `publishes`, `commands`, `AboutCommand::add`, `afterBootstrapping`, facades, `env()`,
`Arr`, `Command::table()`, the `Illuminate\Config\Repository` base class). CI runs the test suite against
all three majors, with PHPStan deprecation rules and PHPUnit `failOnDeprecation` on, so a deprecated API
fails the build rather than the upgrade.

## Development

```sh
docker/run.sh composer install      # PHP CLI + ext-dba + pcov
docker/run.sh composer check        # php-cs-fixer --dry-run, phpstan (level max), phpunit with 100% line coverage
PHP_VERSION=8.4 docker/run.sh composer check
```

`composer.json` also carries a `config.policy.advisories.ignore` entry for `laravel/framework <12.0`:
Composer 2.10+ refuses to install a package version with an unpatched security advisory unless it is
explicitly allow-listed, and Laravel 10 and 11 are supported on purpose here, so the entry silences
advisories against those two majors for this package's own `composer update`.

## About this code

The package was written with Claude (Anthropic) from a specification, under human direction and review.
Every line is covered by tests, static analysis and CI; the maintainers are responsible for the code as
for any other. Issues and pull requests are welcome.

## License

MIT.
