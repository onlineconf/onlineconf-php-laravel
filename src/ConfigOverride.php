<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Arr;
use LogicException;
use Onlineconf\Laravel\Config\MapEntry;
use Onlineconf\Laravel\Config\OverridingRepository;
use Onlineconf\Module;

/**
 * Makes config() read mapped keys from OnlineConf. One line in bootstrap/app.php:
 *
 *     \Onlineconf\Laravel\ConfigOverride::register($app);
 *
 * The map is derived from the {@see Ref} markers in config/*.php and merged with the explicit
 * "onlineconf.map"; the kill switch lives in the application's published config/onlineconf.php.
 */
final class ConfigOverride
{
    /**
     * Installs the override right after the configuration is loaded, before any service provider runs.
     */
    public static function register(Application $app): void
    {
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
            self::install($app);
        });
    }

    /**
     * Takes every {@see Ref} marker out of the loaded configuration, leaving its fallback behind, and writes
     * the map it declares — merged with the explicit "onlineconf.map" — back to "onlineconf.map". The markers
     * are always replaced, kill switch or not, so config() never hands a marker object to the application.
     *
     * When the override is enabled and the map is not empty, the "config" repository is replaced with an
     * {@see OverridingRepository}; the module manager it uses is put into the container so the service
     * provider shares it. Reads that already happened while config/*.php was loading ({@see EagerReads})
     * are reported to the on_missing handler here, map or no map; with the kill switch off nothing is
     * reported, because immediate reads were switched off too and every one of them "missed".
     */
    public static function install(ApplicationContract $app): void
    {
        $config = $app->make(Repository::class);
        assert($config instanceof Repository);
        $items = $config->all();
        $map = self::derive($items) + MapEntry::normalize(Arr::get($items, 'onlineconf.map'));
        Arr::set($items, 'onlineconf.map', $map);

        if (!(bool) Arr::get($items, 'onlineconf.config_override', true)) {
            self::writeBack($config, $items);

            return;
        }

        $onMissing = self::onMissing($app, Arr::get($items, 'onlineconf.on_missing'));
        if ($map === []) {
            self::writeBack($config, $items);
            self::reportEagerReads($app, $items, $onMissing);

            return;
        }

        $manager = ModuleManagerFactory::fromContainer($app);
        $app->instance(ModuleManager::class, $manager);

        $app->instance('config', new OverridingRepository(
            $items,
            $map,
            static fn (): Module => $manager->module(),
            ModuleManagerFactory::logger($app, Arr::get($items, 'onlineconf.log_channel')),
            $onMissing,
        ));

        self::reportEagerReads($app, $items, $onMissing);
    }

    /**
     * Replaces every marker in the configuration with its fallback and returns the map the markers declare.
     *
     * @param array<mixed> $items
     *
     * @return array<string, array{path: string, type: string|null, required: bool}>
     */
    private static function derive(array &$items, string $prefix = ''): array
    {
        $map = [];
        foreach ($items as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if ($value instanceof Ref) {
                $map[$dotted] = ['path' => $value->path, 'type' => $value->type, 'required' => $value->required];
                $items[$key] = $value->fallback;

                continue;
            }
            if (is_array($value)) {
                $nested = $value;
                $map += self::derive($nested, $dotted);
                $items[$key] = $nested;
            }
        }

        return $map;
    }

    /**
     * Puts the marker-free configuration back into the repository that stays in place.
     *
     * @param array<mixed> $items
     */
    private static function writeBack(Repository $config, array $items): void
    {
        foreach ($items as $key => $value) {
            $config->set((string) $key, $value);
        }
    }

    /**
     * Reports the nodes that {@see \Onlineconf\Laravel\Facades\Onlineconf} read while config/*.php was
     * loading and did not find. The config key is empty: such a read has no config key, only a call site.
     * A module file that could not be opened is logged once instead — an availability failure, not a
     * migration gap, so the on_missing handler does not hear about those reads.
     *
     * @param array<mixed>                     $items
     * @param Closure(MissingValue): void|null $onMissing
     */
    private static function reportEagerReads(ApplicationContract $app, array $items, ?Closure $onMissing): void
    {
        $openError = EagerReads::unreportedOpenError();
        if ($openError !== null) {
            ModuleManagerFactory::logger($app, Arr::get($items, 'onlineconf.log_channel'))->error(
                'OnlineConf is unavailable, the reads in config/*.php fell back to their defaults: ' . $openError,
            );
        }
        foreach (EagerReads::unreported() as $read) {
            if ($onMissing !== null && $read->missing) {
                $onMissing(new MissingValue('', $read->path, $read->default, $read->module, $read->trace));
            }
        }
    }

    /**
     * The on_missing handler as a Closure: null stays null, a Closure is used as is, a class name is resolved
     * from the container on the first call (the container is not booted when the override is installed).
     *
     * @return Closure(MissingValue): void|null
     *
     * @throws LogicException for any other value
     */
    private static function onMissing(ApplicationContract $app, mixed $handler): ?Closure
    {
        if ($handler === null) {
            return null;
        }
        if ($handler instanceof Closure) {
            return $handler;
        }
        if (is_string($handler) && $handler !== '') {
            if (!class_exists($handler) && !$app->bound($handler)) {
                throw new LogicException(sprintf('onlineconf.on_missing: class %s does not exist', $handler));
            }

            return static function (MissingValue $missing) use ($app, $handler): void {
                $callable = $app->make($handler);
                if (!is_callable($callable)) {
                    throw new LogicException(sprintf('onlineconf.on_missing: %s is not invokable', $handler));
                }
                $callable($missing);
            };
        }

        throw new LogicException('onlineconf.on_missing must be null, a class name or a Closure');
    }
}
