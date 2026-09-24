<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Arr;
use Onlineconf\Laravel\Config\OverridingRepository;
use Onlineconf\Module;

/**
 * Makes config() read the nodes declared in config/*.php. One line in bootstrap/app.php:
 *
 *     \Onlineconf\Laravel\ConfigOverride::register($app);
 *
 * The map is derived from the {@see Ref} markers in config/*.php; there is no map to write by hand.
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
     * the map the markers declare to "onlineconf.map", where {@see Console\MapCommand} reads it — that key is
     * output, not input. The "config" repository is then replaced with an {@see OverridingRepository}, and the
     * module manager it uses is put into the container so the service provider shares it.
     *
     * A configuration without markers is left alone: there is nothing to override.
     */
    public static function install(ApplicationContract $app): void
    {
        try {
            self::apply($app);
        } finally {
            // The configuration is loaded: the module opened for it has nothing left to serve, and a worker
            // should not keep its dba handle for the life of the process.
            ImmediateModule::flush();
        }
    }

    private static function apply(ApplicationContract $app): void
    {
        $config = $app->make(Repository::class);
        assert($config instanceof Repository);
        $items = $config->all();
        $map = self::derive($items);
        Arr::set($items, 'onlineconf.map', $map);
        EagerReads::trim();

        if ($map === []) {
            self::writeBack($config, $items);

            return;
        }

        $manager = ModuleManagerFactory::fromContainer($app);
        $app->instance(ModuleManager::class, $manager);

        $app->instance('config', new OverridingRepository(
            $items,
            $map,
            static fn (): Module => $manager->module(),
            ModuleManagerFactory::logger($app, Arr::get($items, 'onlineconf.log_channel')),
        ));
    }

    /**
     * Replaces every marker in the configuration with its fallback and returns the map the markers declare.
     *
     * @param array<mixed> $items
     *
     * @return array<string, array{path: string, type: string, required: bool}>
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
}
