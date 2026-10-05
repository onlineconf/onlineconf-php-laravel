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
php artisan onlineconf:get /app/hosts/main                       # www.example.com
php artisan onlineconf:get --tree /app/nginx/anti-ddos           # pretty JSON of the subtree
php artisan onlineconf:get --json /app/services/billing/admin_emails | jq .
php artisan about --only=onlineconf                              # directory, module file, version
```

The same tree works for the `config()` override below. With `ConfigOverride::register($app)` installed and
these markers in `config/app.php` and `config/session.php`:

```php
use Onlineconf\Laravel\Facades\Onlineconf;

'url'      => Onlineconf::getRefString('/app/hosts/main', 'localhost'),            // → "www.example.com"
'lifetime' => Onlineconf::getRefInt('/app/nginx/anti-ddos/cookie-ttl', 120),       // → 7200
'debug'    => Onlineconf::getRefBool('/app/nginx/anti-ddos/debug', false),         // → false ("0")
```

`config('session.lifetime')` returns the integer `7200` and `config('app.debug')` returns `false`, while
every other key keeps its value from `config/*.php`. Unset `ONLINECONF_DIR` (or set it in `.env`) when you
are done. Without a module file the application then fails to boot — the module is required unless
`ONLINECONF_REQUIRED=false` says otherwise (see «A missing module file» below); with it, every marker serves
the fallback next to it.

## Configuration

`config/onlineconf.php`:

| key | env | default | meaning |
|---|---|---|---|
| `dir` | `ONLINECONF_DIR` | `null` | directory with module files; `null` lets the client resolve it |
| `required` | `ONLINECONF_REQUIRED` | `null` | whether the module file must exist; `null` = the process environment, required when unset; `false`/`0` = optional (see «A missing module file») |
| `module` | `ONLINECONF_MODULE` | `null` | default module: a name (`TREE`) or a file path; `null` = client default |
| `check_interval` | `ONLINECONF_CHECK_INTERVAL` | `5` | seconds between `stat()` checks for updates, `0` = every access |
| `log_channel` | `ONLINECONF_LOG_CHANNEL` | `null` | log channel for the client's warnings; `null` = default logger |

With `dir` and `module` unset the client's own resolution applies: `ONLINECONF_DIR`, `ONLINECONF_CONFIG`
and `CDB_CONFIG_FILE` from the **process environment**, then `/usr/local/etc/onlineconf.yaml`, then
`/usr/local/etc/onlineconf` and `TREE`. Laravel's Dotenv keeps `putenv()` enabled by default, so values from
`.env` do reach `getenv()` and the client's own resolution sees them. Put `ONLINECONF_DIR` (and
`ONLINECONF_MODULE` if needed) into `.env`; the two config keys above are the explicit alternative and win
over the environment. `config:cache` is safe: `env()` is read only inside the config file.

The **immediate reads** of the next section run before `config/onlineconf.php` is loaded and read
`ONLINECONF_DIR`, `ONLINECONF_CONFIG`, `CDB_CONFIG_FILE` and `ONLINECONF_REQUIRED` as `env()` sees them —
`$_SERVER`, `$_ENV`, then `getenv()` — so an application with `Env::disablePutenv()` (Testbench does it) gets
the same values on both paths. A `config:cache`d process does not read `.env` at all and runs no config file;
there only the cached `onlineconf.*` keys count.

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
Onlineconf::getString('/my/service/db/host', env('DB_HOST'));   // a null default is fine: null when absent
Onlineconf::requireStrings('/my/service/hosts');
Onlineconf::subtree('/my/service')->getBool('/enabled', false);
Onlineconf::module('other')->getString('/key', '');      // another module: <dir>/other.cdb or a file path
```

The typed getters take the client's defaults (`onlineconf/onlineconf` 1.3): optional and nullable, and a string
read with the rules of a node value, so `env()` goes in as it is — `Onlineconf::getInt('/db/port',
env('DB_PORT'))` — with no `(int)` cast that would turn an unset variable into `0`:

| Getter | A string default is read as | Examples |
|---|---|---|
| `getInt`, `getDurationMs` | an integer, a duration in milliseconds | `"3306"` → `3306`, `"1.5s"` → `1500` |
| `getFloat`, `getDuration` | a number, a duration in seconds | `"0.5"` → `0.5`, `"1m"` → `60.0` |
| `getBool` | `"1"` or `"0"` only | `"0"` → `false`, `"true"` is an error |
| `getStrings` | comma-separated, or a JSON array of strings when it starts with `[` | `"a, b"` → `["a", "b"]` |
| `getArray` | JSON | `'{"pool":5}'` → `["pool" => 5]` |

`null` and `""` give `null` — an empty variable is an unset one; `getString()` and `get()` take their default
as it is. A default that does not read is an `Onlineconf\Exception\InvalidDefaultException` naming the path
and the type, thrown before the node is read — so a broken `.env` fails everywhere, not only where the tree
has no value. The return type follows the default for PHPStan: `getInt('/p', '80')` is an `int`,
`getInt('/p', env('X'))` an `?int`.

The facade proxies to the default module; its short name coincides with the client's static registry
`Onlineconf\Onlineconf`, which this package does not use — import the facade, not the registry.

Paths are always full paths. There is no application prefix; use `subtree()` where a prefix helps.

### Runtime behaviour

- **PHP-FPM**: one `Module` per request, `dba_open` on the first read and one `stat()` per request.
- **Octane, Horizon, queue workers, long-running commands**: the same `Module` lives as long as the
  process and reloads itself when the file changes (`check_interval`). Code that caches values derived
  from the configuration should compare `Onlineconf::version()` or call `Onlineconf::checkForUpdates()`
  itself; the package adds no hooks.
- Opening the module file happens on the first use (first injection of `Module`, first facade call), or at
  boot when `ConfigOverride` is installed and the configuration has markers. What a missing file means is
  the mode's — see the next section. A file that is there but cannot be opened is the client's
  `OpenException` in either mode: a broken delivery, not a missing one.

### A missing module file

The module file is **required** unless `ONLINECONF_REQUIRED` says otherwise — read by the client, in the
process environment, and by this package from `onlineconf.required` (`env('ONLINECONF_REQUIRED')`, which a
`config:cache`d process keeps):

| `ONLINECONF_REQUIRED` | Mode | Without the module file |
|---|---|---|
| unset, empty, or anything else | strict (the default) | the boot fails: the client's `OpenException`, `<file>: no such file; set ONLINECONF_REQUIRED=false to start without it` |
| `false` (any case) or `0` | tolerant | an empty module: `get*` give their defaults, `require*` throw `NotFoundException` naming the missing file, `about` shows `missing` |

- **Strict** is for production: a pod without the OnlineConf volume, or a node the updater has not reached,
  does not start on the defaults of `config/*.php` by accident. It fails where the module is first used: an
  immediate read in a config file, `ConfigOverride::install()` when the configuration has markers (the module
  is opened there on purpose, so the boot fails rather than the first request), the first `Module` injection
  or facade call. That includes `php artisan config:cache`: run it where the module is mounted — an
  initContainer too.
- **`config:cache` freezes the mode and the immediate reads.** The cache holds `onlineconf.required` as the
  caching process saw it, and a cached configuration wins over the process environment, as it wins over
  `.env` — so a cache written with `ONLINECONF_REQUIRED=false` makes every process that boots from it tolerant,
  production included. It also holds the values the immediate reads returned while caching: written without
  the module, they are the defaults for good. Never cache with the tolerant mode or without the module; mount
  the module where `config:cache` runs.
- **Tolerant** is for development machines, CI and test suites, which have no OnlineConf at all: put
  `ONLINECONF_REQUIRED=false` in `.env`, `.env.testing` or `phpunit.xml`. Everything then goes through the
  client's getters on an empty module, so a missing file and a missing node look the same — the defaults —
  and the types are the same as with a file. The module looks for its file again on every update check
  (`check_interval`) and serves it once it appears, without a restart.
- `Onlineconf::fake()` needs no module file in either mode.
- A file that is not there raises no PHP warning, not even a suppressed one, so test runners that report
  those (Collision) stay quiet.

## Testing your application

A test suite without OnlineConf needs the tolerant mode, or the application does not boot:

```xml
<!-- phpunit.xml -->
<env name="ONLINECONF_REQUIRED" value="false"/>
```

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

Fakes need no module files, in either mode; child lists are generated, so `children()` and `getTree()` work on them. A fake
also feeds the `config()` override below, so `config('services.mailer.host')` returns the faked value.

## Overriding config() values

To migrate settings from `.env` to OnlineConf without touching the code that calls `config()`, wrap their
values in `config/*.php` in markers and install the override in `bootstrap/app.php`.

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

and name the nodes in `config/*.php` with the markers of the next section:

```php
// config/services.php
'mailer' => [
    'host' => Onlineconf::getRefString('/my/service/mailer/host', env('MAIL_HOST')),
    'port' => Onlineconf::getRefInt('/my/service/mailer/port', 25),
],
```

From then on `config('services.mailer.host')` is read from OnlineConf. The value written in `config/*.php`
(usually `env(...)`) stays as the fallback and is returned whenever: OnlineConf has no such node, the value
does not parse as the declared type (a warning is logged, in the client's own wording), the value is invalid
JSON (an error is logged), or the module file is not there and the mode is tolerant (see «A missing module
file»). Reading
`config('services')` as a whole includes the marked keys under it; an explicit `config()->set()` at runtime
wins over the map.

- There is one map and it is derived: `config('onlineconf.map')` holds what the markers declared —
  `['path' => ..., 'type' => ..., 'required' => bool]` per config key — and is what `onlineconf:map` prints.
  It is the package's output; on a boot from `config:cache`, where the markers are already resolved, it is
  also the input the override is installed from. Writing that key by hand is not supported.
- Migrate one key at a time by turning `env(...)` into a marker around it.
- `config:cache` is supported. The caching run boots the application, override included, so the cache holds
  the fallbacks and the derived map; the cached boot installs the override from that map, and the marked
  keys keep reading OnlineConf.
- Reads are lazy, so long-running workers see a changed value on the next `config()` call. Services that read
  their settings once in a constructor keep them, as with any Laravel configuration.
- `set()` at runtime unmaps the written key, everything below it and everything above it: an explicit write
  wins over the map for good, not just until the next read.
- An unparsable value is logged verbatim at `warning` level (e.g. `"abc" is not an integer`), so a malformed
  secret can end up in the log.
- Not covered: `env()` calls outside `config/*.php` and `getenv()`; move them into a config file first. Also
  not covered: `config()->all()` — it returns the loaded configuration without substitution (this is what
  keeps `config:cache` safe), so code or packages reading `all()` see the fallbacks.
- Cost: an unmarked key costs one extra array lookup; a marked key is one `dba_fetch` on first read per
  process, then the client's cache. A tolerant module without its file costs one `stat()` per update check.

## Naming nodes in config/*.php

A node is named where the value lives, in one of two ways, and one rule says which:

- **`getRef*()` / `requireRef*()` — a lazy reference.** The value stays in the configuration as a marker;
  `ConfigOverride::install()` replaces it with the fallback and adds it to the map, so every `config()` call
  reads OnlineConf. Use it when the value is used **as is**.
- **`get*()` / `require*()` — an immediate read.** The facade reads OnlineConf right there, while
  `config/*.php` is being loaded, and the plain value lands in the configuration. Use it when the config file
  **transforms** the value — a cast, `explode()`, string concatenation, a condition — and the value may be
  fixed for the life of the process; otherwise give the lazy marker a transform (see «Post-processing»).

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
because the cast sees an object, not the node — which is exactly why a cast in the config file means `get*`,
or a transform on the marker. Using a marker as a string throws a `LogicException` naming the path instead of
failing quietly.

**Markers do not belong in `config/onlineconf.php`, `app.env` or `app.timezone`.** The package's own settings
(`dir`, `module`, `check_interval`, `log_channel`) and the keys `LoadConfiguration` itself consumes
(`app.env`, `app.timezone`) are read before the markers are resolved, so a marker there would be read as an
object. An immediate `get*` works in those files, with the caveat that its own directory comes
from the environment.

**Required nodes.** `requireRefString('/my/app/secret')` and its siblings take no fallback: the node must
exist, and the override calls the client's `require*`, so `NotFoundException` (or `FormatException`,
`ParseException`) reaches the caller instead of a silent fallback. `getRefString('/my/app/secret', null)` is
the other case — a node that may be absent, with `null` as its fallback. Without a module file a required
node throws in both modes: the strict `OpenException`, or in tolerant mode a `NotFoundException` that names
the missing file. Use `requireRef*()` only where the application genuinely cannot run on a default, because a
developer machine without OnlineConf cannot run that code path either.

**Fallbacks are read like defaults.** `getRefInt('/db/port', env('DB_PORT'))` takes the `env()` string as it is:
`ConfigOverride::install()` reads the fallback with the client's parser of the declared type before anything
else — so the configuration holds an `int`, a transform receives an `int`, `""` is `null`, and a fallback that
does not read fails the boot with an `InvalidDefaultException`. `onlineconf:map` still shows the fallback as
written.

The exception also surfaces on an ancestor read: `config('database')` reads every marked key below it, so a
missing required node throws there as well, not only on `config('database.connections.mysql.password')`.

When the node exists but does not parse as the declared type, the value from `config/*.php` is returned as it
is — including `null` — and the client's warning is logged. The fallback is never replaced by an empty value
of the declared type.

### Post-processing

Every lazy marker takes an optional transform — the last argument: `getRef*($path, $fallback, $transform)`,
`requireRef*($path, $transform)` — for a value the config file would otherwise have to change:

```php
// config/services.php: a comma-separated list, kept lazy
'hosts' => Onlineconf::getRefString(
    '/my/service/hosts',
    env('SERVICE_HOSTS'),
    fn (?string $hosts): array => array_filter(array_map('trim', explode(',', (string) $hosts))),
),
```

- **The declared type is the type of the node** — how the client parses the tree's value. The transform gets
  that typed value and whatever it returns is what `config()` returns.
- **It is applied to the fallback as well**, so the key has the same shape with and without a node: the
  fallback is written raw (here the `env()` string) and `ConfigOverride::install()` stores the transformed
  fallback in the configuration — `config()` on a machine without OnlineConf, `config()->all()` and
  `config:cache` all see the final shape. A `requireRef*()` marker has no fallback, so nothing is shaped there.
- **Its parameter must accept `null`** whenever the fallback can be `null` (an unset `env()`): the transform
  receives the fallback exactly as written.
- **It runs once per module version**, not on every `config()` call: the result is memoised per config key
  and computed again when the module reloads, and every `config()` call of that version gets the same
  instance. Keep it a pure function of its argument.
- **It must be storable**, because `config:cache` writes the configuration with `var_export()`: a `Closure`
  (stored with `laravel/serializable-closure`), a function name (`'strtolower'`) or a static method
  (`[Csv::class, 'split']`, or `Csv::split(...)`). Rejected with an `InvalidArgumentException` naming the path
  and the form to write instead, when the marker is built: an invokable object, an object method (`[$obj, 'm']`
  or `$obj->m(...)`), a first-class callable of a function (`trim(...)` or one of the application's: write
  `'trim'`) and of a PHP class's static method (`DateTime::createFromFormat(...)`: write
  `[DateTime::class, 'createFromFormat']`). A function that does not exist, or a non-static method written as
  `[Csv::class, 'method']`, is not a callable at all: PHP rejects it with a `TypeError` for the `?callable`
  parameter.
- **A closure is serialized when the marker is built**: its `use` variables are copied then, and a
  by-reference `use` is never shared with the code around it. The stored closure is unsigned — the
  configuration cache is trusted local PHP — so it does not depend on `APP_KEY` or its rotation.
- **A transform that throws is a programming error, not a missing value**: it propagates as a
  `RuntimeException` naming the config key and the path, on the node value from `config()` and on the
  fallback from `ConfigOverride::install()`.
- The immediate `get*()` / `require*()` take no transform: the config file can wrap them directly.
- `onlineconf:map` marks transformed keys in its `Transform` column and shows the fallback as written.

### Reading a marker on its own

A marker can also read its node on demand: `$ref->value()` returns the node's value right now, through the
marker's transform. It is the facade's immediate read of the declared type — `get<Type>($path, $fallback)`,
or `require<Type>($path)` for a `requireRef*()` marker — so it works both while `config/*.php` loads and after
boot, which here means after the package's service provider has registered. The absence rules are those of
`config()`: no node, or no module file in tolerant mode, gives the fallback, read as a default (the client's
exception for a required marker); no module file in strict mode is the `OpenException`; a value that does not
parse gives the client's warning and the fallback, invalid JSON an error and the fallback.

```php
$hosts = Onlineconf::getRefString('/my/service/hosts', config('services.hosts'), [Csv::class, 'split']);

$hosts->value();   // the list, read now
```

It is there for code outside `config/*.php` that wants one declaration — path, type, fallback, transform — in
several places. `onlineconf:map` does not list a read after boot; a read while the configuration loads appears
among its immediate reads. Inside config files prefer a plain marker (lazy) or `get*()` (immediate).

The value is never memoised — the client caches the raw values per module version — but the marker keeps its
decoded transform. That only pays off when the marker is kept: `Onlineconf::getRefString(..., fn ...)->value()`
written inline serializes and unserializes the closure on every call, so build the marker once, in a property
or a static property filled on first use, and call `value()` on it. A kept marker also keeps the closure's `static` variables
and the objects its `use` captured between calls — one more reason to keep transforms pure.

### What immediate reads cost

`get*()` in `config/*.php` runs before the service providers, so the facade cannot use the container:

- **The value is fixed for the life of the process** and is baked into `config:cache`. A node that changes
  while a worker runs is not picked up — use `getRef*()` for anything that should follow the tree.
- **The module directory comes from the process environment** (`ONLINECONF_DIR`, `ONLINECONF_CONFIG`,
  `CDB_CONFIG_FILE`, then the client's defaults), not from `config/onlineconf.php`, which is not loaded yet.
  `.env` is already loaded at that point, so `ONLINECONF_DIR` in `.env` works; `onlineconf.dir` does not.
- **A missing module file follows the mode**, read from the process environment here as well: strict, the
  first immediate read fails the boot; tolerant, `get*` give their defaults, exactly as `config()` does on the
  lazy path, and `require*` throw. The same holds for `php artisan config:cache`, which loads the
  configuration of a second application while the facade still belongs to the first, already booted one.
- Immediate reads are recorded for `onlineconf:map`, whether or not they found anything.

### Seeing what is referenced

```sh
php artisan onlineconf:map           # config key | path | type | required | fallback, then the immediate reads
php artisan onlineconf:map --json    # the same as JSON, for dumping values or diffing against the tree
```

The command reports what the current process loaded: it needs `ConfigOverride::register($app)` in
`bootstrap/app.php` to see the markers at all, and under `config:cache` it lists no immediate reads, because
the config files did not run — their values came from the cache.

### Why there are no reports

A node OnlineConf does not have is not an error and is not reported anywhere: the value in `config/*.php` —
usually `env(...)` — **is** the default, and the tree holds only what must differ from that default or change
without a deploy. A tree that answers nothing is a correct tree for an application that is happy with its
defaults — and a developer machine with no module file at all works once it says `ONLINECONF_REQUIRED=false`.

What does get said out loud:

- a value that does not parse as the declared type — `warning`, in the client's own wording;
- a value that is not the JSON it claims to be — `error`;
- no module file in strict mode — the client's `OpenException`, at boot; in tolerant mode, nothing;
- a default or a fallback that does not read as its type — `InvalidDefaultException`, at the call or at boot;
- a node a `requireRef*()` marker declares and OnlineConf does not have — the client's `NotFoundException`,
  thrown at the `config()` call, because that node was declared as one that must exist.

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
directory is not writable, the JSON is invalid or the arguments are wrong. The module file must exist — the
command does not create one. It is a development tool for a copy of a module taken from a real environment;
production modules are written by `onlineconf-updater` only.

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
