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

## Configuration

`config/onlineconf.php`:

| key | env | default | meaning |
|---|---|---|---|
| `dir` | `ONLINECONF_DIR` | `null` | directory with module files; `null` lets the client resolve it |
| `module` | `ONLINECONF_MODULE` | `null` | default module: a name (`TREE`) or a file path; `null` = client default |
| `check_interval` | `ONLINECONF_CHECK_INTERVAL` | `5` | seconds between `stat()` checks for updates, `0` = every access |
| `log_channel` | `ONLINECONF_LOG_CHANNEL` | `null` | log channel for the client's warnings; `null` = default logger |
| `config_override` | `ONLINECONF_CONFIG_OVERRIDE` | `true` | kill switch of the `config()` override below |
| `map` | — | `[]` | Laravel config key → OnlineConf path for the `config()` override below |

With `dir` and `module` unset the client's own resolution applies: `ONLINECONF_DIR`, `ONLINECONF_CONFIG`
and `CDB_CONFIG_FILE` from the **process environment**, then `/usr/local/etc/onlineconf.yaml`, then
`/usr/local/etc/onlineconf` and `TREE`. Laravel does not export `.env` values to the process environment,
so a value that lives only in `.env` reaches the client only through the two config keys above — put
`ONLINECONF_DIR` (and `ONLINECONF_MODULE` if needed) into `.env`, not `CDB_CONFIG_FILE`. `config:cache` is
safe: `env()` is read only inside the config file.

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

and in the published `config/onlineconf.php`:

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
the module file cannot be opened (an error is logged once). The OnlineConf value is read with the type of the
fallback: a `bool` in the config file means `getBool`, an `int` means `getInt`, an array means `getArray` (a
JSON value in OnlineConf), a string means `getString`, `null` means the raw value. Reading `config('services')`
as a whole includes the mapped keys under it; an explicit `config()->set()` at runtime wins over the map.

- Migrate one key at a time by adding it to the map; `ONLINECONF_CONFIG_OVERRIDE=false` in `.env` turns the
  whole override off.
- The map lives in the application's published `config/onlineconf.php`: at the moment the override is
  installed, package defaults are not merged yet, so an unpublished config means an empty map.
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

## Artisan

```
php artisan onlineconf:get /my/service/db/host
php artisan onlineconf:get --json /my/service/db/opts | jq .
php artisan onlineconf:get --tree /my/service
php artisan onlineconf:get --module=other /key
php artisan about --only=onlineconf
```

`onlineconf:get` prints `s` values as is and `j` values as the stored JSON text; `--json` encodes any
value as JSON, `--tree` prints `getTree()` as pretty JSON (on a path with no descendants this is the
client's `getTree()` behaviour: `null`, exit `0`, not a "not found" error). Exit codes: `0`; `1` when the
key does not exist; `2` on file, format or invalid-JSON errors. `about` shows the directory, the default
module file and the version of the loaded data.

## Compatibility

The package uses only framework APIs that are identical in Laravel 10, 11 and 12 (`singletonIf`, `bind`,
`mergeConfigFrom`, `publishes`, `commands`, `AboutCommand::add`, `afterBootstrapping`, facades, the
`Illuminate\Config\Repository` base class). CI runs the test suite against
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
