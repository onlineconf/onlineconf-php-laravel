<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Onlineconf\Laravel\ModuleManager;
use Onlineconf\Module;
use Onlineconf\Source\ArraySource;
use Onlineconf\Subtree;

/**
 * Facade over the default {@see Module}.
 *
 * @method static string        name()
 * @method static string        version()
 * @method static bool          checkForUpdates()
 * @method static ?string       getRaw(string $path)
 * @method static bool          has(string $path)
 * @method static string        getString(string $path, string $default)
 * @method static int           getInt(string $path, int $default)
 * @method static float         getFloat(string $path, float $default)
 * @method static bool          getBool(string $path, bool $default)
 * @method static float         getDuration(string $path, float $default)
 * @method static int           getDurationMs(string $path, int $default)
 * @method static list<string>  getStrings(string $path, list<string> $default)
 * @method static array<mixed>  getArray(string $path, array<mixed> $default)
 * @method static mixed         get(string $path, mixed $default)
 * @method static mixed         require(string $path)
 * @method static string        requireString(string $path)
 * @method static int           requireInt(string $path)
 * @method static float         requireFloat(string $path)
 * @method static bool          requireBool(string $path)
 * @method static float         requireDuration(string $path)
 * @method static int           requireDurationMs(string $path)
 * @method static list<string>  requireStrings(string $path)
 * @method static array<mixed>  requireArray(string $path)
 * @method static Subtree       subtree(string $prefix)
 * @method static list<string>  children(string $path)
 * @method static mixed         getTree(string $path, ?int $maxDepth = null)
 * @method static void          walk(string $path, callable $visitor, ?int $maxDepth = null)
 *
 * @see Module
 */
final class Onlineconf extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Module::class;
    }

    /**
     * The default module for null, otherwise a module by name ("TREE") or by file path.
     */
    public static function module(?string $name = null): Module
    {
        return self::manager()->module($name);
    }

    /**
     * Replaces the module (the default one for null) with an in-memory module built from PHP values.
     * Returns the source; {@see ArraySource::replaceValues()} on it is visible on the next read.
     *
     * @param array<string, string|int|float|bool|array<mixed>|null> $values path → PHP value
     */
    public static function fake(array $values = [], ?string $name = null): ArraySource
    {
        $source = self::manager()->fake($values, $name);
        static::clearResolvedInstance(Module::class);

        return $source;
    }

    private static function manager(): ModuleManager
    {
        $app = static::getFacadeApplication();
        assert($app !== null);
        $manager = $app->make(ModuleManager::class);
        assert($manager instanceof ModuleManager);

        return $manager;
    }
}
