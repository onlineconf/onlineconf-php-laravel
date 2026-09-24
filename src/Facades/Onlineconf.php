<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Onlineconf\Exception\OpenException;
use Onlineconf\Laravel\EagerReads;
use Onlineconf\Laravel\ImmediateModule;
use Onlineconf\Laravel\ModuleManager;
use Onlineconf\Laravel\Ref;
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
    /** @var array<string, string> read method → the type it declares; everything else is not a node read */
    private const READS = [
        'get' => Ref::TYPE_RAW,
        'getString' => Ref::TYPE_STRING,
        'getInt' => Ref::TYPE_INT,
        'getFloat' => Ref::TYPE_FLOAT,
        'getBool' => Ref::TYPE_BOOL,
        'getDuration' => Ref::TYPE_DURATION,
        'getDurationMs' => Ref::TYPE_DURATION_MS,
        'getStrings' => Ref::TYPE_STRINGS,
        'getArray' => Ref::TYPE_ARRAY,
        'require' => Ref::TYPE_RAW,
        'requireString' => Ref::TYPE_STRING,
        'requireInt' => Ref::TYPE_INT,
        'requireFloat' => Ref::TYPE_FLOAT,
        'requireBool' => Ref::TYPE_BOOL,
        'requireDuration' => Ref::TYPE_DURATION,
        'requireDurationMs' => Ref::TYPE_DURATION_MS,
        'requireStrings' => Ref::TYPE_STRINGS,
        'requireArray' => Ref::TYPE_ARRAY,
    ];

    protected static function getFacadeAccessor(): string
    {
        return Module::class;
    }

    /**
     * {@inheritDoc}
     *
     * Until the service provider binds {@see Module}, which happens after config/*.php is loaded, the call
     * goes to the process-wide {@see ImmediateModule} and is recorded in {@see EagerReads}. That is what
     * makes Onlineconf::getString() usable in config/*.php in place of env(). A module file that cannot be
     * opened is a fallback for get* and an exception for require*.
     *
     * @param string       $method
     * @param array<mixed> $args
     */
    public static function __callStatic($method, $args): mixed
    {
        $app = static::getFacadeApplication();
        if ($app !== null && $app->bound(Module::class)) {
            return parent::__callStatic($method, $args);
        }

        return self::immediate($method, $args);
    }

    /**
     * @param array<mixed> $args
     */
    private static function immediate(string $method, array $args): mixed
    {
        $type = self::READS[$method] ?? null;
        $optional = $type !== null && !str_starts_with($method, 'require');
        $default = $optional ? ($args[1] ?? null) : null;

        try {
            $module = ImmediateModule::module();
        } catch (OpenException $e) {
            // A module file that is not there yet must not stop the application from booting: get* fall back
            // to their defaults, exactly as config() does later, and install() logs the failure once.
            EagerReads::openFailed($e->getMessage());
            if (!$optional) {
                throw $e;
            }

            return $default;
        }

        $path = $args[0] ?? null;
        if ($type !== null && is_string($path)) {
            EagerReads::record($path, $type, $default, !$module->has($path), $module->name());
        }

        /** @var callable $callable */
        $callable = [$module, $method];

        return $callable(...$args);
    }

    /**
     * A marker for config/*.php read as a string on every config() call; without a fallback the node is required.
     */
    public static function refString(string $path, ?string $fallback = null): Ref
    {
        return new Ref($path, Ref::TYPE_STRING, $fallback, func_num_args() < 2);
    }

    /**
     * A marker read with getInt()/requireInt(); without a fallback the node is required.
     */
    public static function refInt(string $path, ?int $fallback = null): Ref
    {
        return new Ref($path, Ref::TYPE_INT, $fallback, func_num_args() < 2);
    }

    /**
     * A marker read with getFloat()/requireFloat(); without a fallback the node is required.
     */
    public static function refFloat(string $path, ?float $fallback = null): Ref
    {
        return new Ref($path, Ref::TYPE_FLOAT, $fallback, func_num_args() < 2);
    }

    /**
     * A marker read with getBool()/requireBool(); without a fallback the node is required.
     */
    public static function refBool(string $path, ?bool $fallback = null): Ref
    {
        return new Ref($path, Ref::TYPE_BOOL, $fallback, func_num_args() < 2);
    }

    /**
     * A marker read with getDuration()/requireDuration(): seconds as a float ("30s", "1m").
     */
    public static function refDuration(string $path, ?float $fallback = null): Ref
    {
        return new Ref($path, Ref::TYPE_DURATION, $fallback, func_num_args() < 2);
    }

    /**
     * A marker read with getDurationMs()/requireDurationMs(): milliseconds as an int.
     */
    public static function refDurationMs(string $path, ?int $fallback = null): Ref
    {
        return new Ref($path, Ref::TYPE_DURATION_MS, $fallback, func_num_args() < 2);
    }

    /**
     * A marker read with getStrings()/requireStrings(): a comma-separated value or a JSON array of strings.
     *
     * @param list<string>|null $fallback
     */
    public static function refStrings(string $path, ?array $fallback = null): Ref
    {
        return new Ref($path, Ref::TYPE_STRINGS, $fallback, func_num_args() < 2);
    }

    /**
     * A marker read with getArray()/requireArray(): a JSON value.
     *
     * @param array<mixed>|null $fallback
     */
    public static function refArray(string $path, ?array $fallback = null): Ref
    {
        return new Ref($path, Ref::TYPE_ARRAY, $fallback, func_num_args() < 2);
    }

    /**
     * A marker read with get()/require(): the raw value, a string or decoded JSON.
     */
    public static function ref(string $path, mixed $fallback = null): Ref
    {
        return new Ref($path, Ref::TYPE_RAW, $fallback, func_num_args() < 2);
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
