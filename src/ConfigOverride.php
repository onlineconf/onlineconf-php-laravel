<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Arr;
use Onlineconf\Laravel\Config\MapEntry;
use Onlineconf\Laravel\Config\OverridingRepository;
use Onlineconf\Module;
use WeakMap;

/**
 * Makes config() read the nodes declared in config/*.php. One line in bootstrap/app.php:
 *
 *     \Onlineconf\Laravel\ConfigOverride::register($app);
 *
 * The map is derived from the {@see Ref} markers in config/*.php; there is no map to write by hand.
 *
 * @phpstan-import-type Entry from MapEntry
 */
final class ConfigOverride
{
    /** @var WeakMap<Repository, true>|null repositories already installed on, so a repeated install() is a no-op */
    private static ?WeakMap $installed = null;

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
     * the map the markers declare to "onlineconf.map", where {@see Console\MapCommand} reads it. The "config"
     * repository is then replaced with an {@see OverridingRepository}, and the module manager it uses is put
     * into the container so the service provider shares it.
     *
     * A configuration from config:cache has no markers left: its map is the one the caching application
     * derived and wrote to "onlineconf.map". A configuration with neither is left alone. A second call on
     * the same configuration changes nothing — neither the repository nor the registry of immediate reads —
     * unless the first one failed: whatever throws — a transform on its fallback, the module manager, the
     * logger — aborts the install, and the next call tries again.
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
        $installed = self::$installed ??= new WeakMap();
        if ($config instanceof OverridingRepository || isset($installed[$config])) {
            return;
        }
        $items = $config->all();
        $transforms = [];
        // No markers left means the configuration came from config:cache written by an application that had
        // already resolved them: the map it derived is in the cached array.
        $map = self::derive($items, $transforms);
        if ($map === []) {
            $map = MapEntry::normalize(Arr::get($items, 'onlineconf.map'), $items);
            foreach ($map as $key => $entry) {
                if ($entry['transform'] !== null) {
                    $transforms[$key] = Transform::decode($entry['transform'], $key, $entry['path']);
                }
            }
        }
        Arr::set($items, 'onlineconf.map', $map);

        if ($map === []) {
            self::writeBack($config, $items);
        } else {
            // The manager already in the container, if any — the provider's, with its fakes — or a new one: this
            // runs before the providers in a real boot.
            $manager = $app->bound(ModuleManager::class) ? $app->make(ModuleManager::class) : ModuleManagerFactory::fromContainer($app);
            assert($manager instanceof ModuleManager);
            // Opened now, so a required module file that is not there fails the boot here, not at the first
            // config() call; an optional one is an empty module that opens the file once it appears.
            $manager->module();
            $app->instance(ModuleManager::class, $manager);
            $app->instance('config', new OverridingRepository(
                $items,
                $map,
                static fn (): Module => $manager->module(),
                ModuleManagerFactory::logger($app, Arr::get($items, 'onlineconf.log_channel')),
                $transforms,
            ));
        }

        // Only a complete install counts: anything that threw above is tried again by the next call.
        $installed[$config] = true;
        EagerReads::trim();
    }

    /**
     * Replaces every marker in the configuration with its fallback and returns the map the markers declare;
     * the transforms, decoded once, are collected for the repository.
     *
     * @param array<mixed>            $items
     * @param array<string, callable> $transforms
     *
     * @return array<string, Entry>
     */
    private static function derive(array &$items, array &$transforms, string $prefix = ''): array
    {
        $map = [];
        foreach ($items as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if ($value instanceof Ref) {
                $map[$dotted] = [
                    'path' => $value->path,
                    'type' => $value->type,
                    'required' => $value->required,
                    'fallback' => $value->fallback,
                    'transform' => $value->transform,
                ];
                // The fallback is read as the client reads a default — a string with the rules of a node value — so
                // the configuration holds the declared type, a transform receives it, and a fallback that does not
                // read fails the boot. A required marker has no fallback.
                $items[$key] = $value->required ? null : $value->clientType()->parseDefault($value->fallback, $value->path);
                if ($value->transform !== null) {
                    $transforms[$dotted] = Transform::decode($value->transform, $dotted, $value->path);
                    // The configuration gets the shaped fallback, so config() without a node and config:cache
                    // already have the final shape.
                    if (!$value->required) {
                        $items[$key] = Transform::apply($transforms[$dotted], $items[$key], $dotted, $value->path);
                    }
                }

                continue;
            }
            if (is_array($value)) {
                $nested = $value;
                $map += self::derive($nested, $transforms, $dotted);
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
