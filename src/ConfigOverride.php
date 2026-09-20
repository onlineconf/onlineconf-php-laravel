<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use LogicException;
use Onlineconf\Laravel\Config\OverridingRepository;
use Onlineconf\Module;

/**
 * Makes config() read mapped keys from OnlineConf. One line in bootstrap/app.php:
 *
 *     \Onlineconf\Laravel\ConfigOverride::register($app);
 *
 * The map and the kill switch live in the application's published config/onlineconf.php.
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
     * Replaces the "config" repository with an {@see OverridingRepository} when the override is enabled and the
     * map is not empty. The module manager it uses is put into the container so the service provider shares it.
     */
    public static function install(ApplicationContract $app): void
    {
        $config = $app->make(Repository::class);
        assert($config instanceof Repository);
        $map = $config->get('onlineconf.map', []);
        if (!(bool) $config->get('onlineconf.config_override', true) || !is_array($map) || $map === []) {
            return;
        }

        $manager = ModuleManagerFactory::fromContainer($app);
        $app->instance(ModuleManager::class, $manager);

        $app->instance('config', new OverridingRepository(
            $config->all(),
            self::stringMap($map),
            static fn (): Module => $manager->module(),
            ModuleManagerFactory::logger($app, $config->get('onlineconf.log_channel')),
            self::onMissing($app, $config->get('onlineconf.on_missing')),
        ));
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

    /**
     * Keeps only "non-empty string => non-empty string" entries.
     *
     * @param array<mixed> $map
     *
     * @return array<string, string>
     */
    private static function stringMap(array $map): array
    {
        $result = [];
        foreach ($map as $key => $path) {
            if (is_string($key) && $key !== '' && is_string($path) && $path !== '') {
                $result[$key] = $path;
            }
        }

        return $result;
    }
}
