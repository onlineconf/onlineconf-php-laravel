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
 * The get*() and require*() calls above read a node now; the getRef*() and requireRef*() constructors below
 * leave a marker in config/*.php that config() reads on every call. The names mirror each other exactly.
 *
 * @see Module
 * @see Ref
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
        // Named arguments reach __callStatic() keyed by name, in the order they were written; the names are
        // the client's own, so they identify the two arguments whatever order they came in.
        $positional = array_values($args);
        $default = $optional ? ($args['default'] ?? $positional[1] ?? null) : null;

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

        $path = $args['path'] ?? $positional[0] ?? null;
        if ($type !== null && is_string($path)) {
            EagerReads::record($path, $type, $default, !$module->has($path), $module->name());
        }

        /** @var callable $callable */
        $callable = [$module, $method];

        return $callable(...$args);
    }

    /**
     * A marker for config/*.php read with the client's getString() on every config() call.
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRefString(string $path, ?string $fallback): Ref
    {
        return new Ref($path, Ref::TYPE_STRING, $fallback);
    }

    /**
     * A marker for config/*.php read with the client's getInt() on every config() call.
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRefInt(string $path, ?int $fallback): Ref
    {
        return new Ref($path, Ref::TYPE_INT, $fallback);
    }

    /**
     * A marker for config/*.php read with the client's getFloat() on every config() call.
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRefFloat(string $path, ?float $fallback): Ref
    {
        return new Ref($path, Ref::TYPE_FLOAT, $fallback);
    }

    /**
     * A marker for config/*.php read with the client's getBool() on every config() call.
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRefBool(string $path, ?bool $fallback): Ref
    {
        return new Ref($path, Ref::TYPE_BOOL, $fallback);
    }

    /**
     * A marker for config/*.php read with the client's getDuration() on every config() call.
     * The node is seconds as a float ("30s", "1m").
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRefDuration(string $path, ?float $fallback): Ref
    {
        return new Ref($path, Ref::TYPE_DURATION, $fallback);
    }

    /**
     * A marker for config/*.php read with the client's getDurationMs() on every config() call.
     * The node is milliseconds as an int.
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRefDurationMs(string $path, ?int $fallback): Ref
    {
        return new Ref($path, Ref::TYPE_DURATION_MS, $fallback);
    }

    /**
     * A marker for config/*.php read with the client's getStrings() on every config() call.
     * The node is a comma-separated value or a JSON array of strings.
     * The fallback is what config() returns while OnlineConf has no such node.
     *
     * @param list<string>|null $fallback
     */
    public static function getRefStrings(string $path, ?array $fallback): Ref
    {
        return new Ref($path, Ref::TYPE_STRINGS, $fallback);
    }

    /**
     * A marker for config/*.php read with the client's getArray() on every config() call.
     * The node is a JSON value.
     * The fallback is what config() returns while OnlineConf has no such node.
     *
     * @param array<mixed>|null $fallback
     */
    public static function getRefArray(string $path, ?array $fallback): Ref
    {
        return new Ref($path, Ref::TYPE_ARRAY, $fallback);
    }

    /**
     * A marker for config/*.php read with the client's get() on every config() call.
     * The node is the raw value: a string, or decoded JSON.
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRef(string $path, mixed $fallback): Ref
    {
        return new Ref($path, Ref::TYPE_RAW, $fallback);
    }

    /**
     * A marker read with the client's requireString(): the node must exist, or config() throws.
     */
    public static function requireRefString(string $path): Ref
    {
        return new Ref($path, Ref::TYPE_STRING, null, true);
    }

    /**
     * A marker read with the client's requireInt(): the node must exist, or config() throws.
     */
    public static function requireRefInt(string $path): Ref
    {
        return new Ref($path, Ref::TYPE_INT, null, true);
    }

    /**
     * A marker read with the client's requireFloat(): the node must exist, or config() throws.
     */
    public static function requireRefFloat(string $path): Ref
    {
        return new Ref($path, Ref::TYPE_FLOAT, null, true);
    }

    /**
     * A marker read with the client's requireBool(): the node must exist, or config() throws.
     */
    public static function requireRefBool(string $path): Ref
    {
        return new Ref($path, Ref::TYPE_BOOL, null, true);
    }

    /**
     * A marker read with the client's requireDuration(): the node must exist, or config() throws.
     */
    public static function requireRefDuration(string $path): Ref
    {
        return new Ref($path, Ref::TYPE_DURATION, null, true);
    }

    /**
     * A marker read with the client's requireDurationMs(): the node must exist, or config() throws.
     */
    public static function requireRefDurationMs(string $path): Ref
    {
        return new Ref($path, Ref::TYPE_DURATION_MS, null, true);
    }

    /**
     * A marker read with the client's requireStrings(): the node must exist, or config() throws.
     */
    public static function requireRefStrings(string $path): Ref
    {
        return new Ref($path, Ref::TYPE_STRINGS, null, true);
    }

    /**
     * A marker read with the client's requireArray(): the node must exist, or config() throws.
     */
    public static function requireRefArray(string $path): Ref
    {
        return new Ref($path, Ref::TYPE_ARRAY, null, true);
    }

    /**
     * A marker read with the client's require(): the node must exist, or config() throws.
     */
    public static function requireRef(string $path): Ref
    {
        return new Ref($path, Ref::TYPE_RAW, null, true);
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
