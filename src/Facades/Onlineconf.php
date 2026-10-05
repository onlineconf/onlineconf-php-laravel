<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Onlineconf\Laravel\EagerReads;
use Onlineconf\Laravel\ImmediateModule;
use Onlineconf\Laravel\ModuleManager;
use Onlineconf\Laravel\Ref;
use Onlineconf\Laravel\Transform;
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
 * @method static ($default is null ? string|null : string) getString(string $path, ?string $default = null)
 * @method static ($default is int|non-empty-string ? int : int|null) getInt(string $path, int|string|null $default = null)
 * @method static ($default is int|float|non-empty-string ? float : float|null) getFloat(string $path, float|string|null $default = null)
 * @method static ($default is bool|non-empty-string ? bool : bool|null) getBool(string $path, bool|string|null $default = null)
 * @method static ($default is int|float|non-empty-string ? float : float|null) getDuration(string $path, float|string|null $default = null)
 * @method static ($default is int|non-empty-string ? int : int|null) getDurationMs(string $path, int|string|null $default = null)
 * @method static ($default is array|non-empty-string ? list<string> : list<string>|null) getStrings(string $path, list<string>|string|null $default = null)
 * @method static ($default is array|non-empty-string ? array<mixed> : array<mixed>|null) getArray(string $path, array<mixed>|string|null $default = null)
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
 * leave a marker in config/*.php that config() reads on every call. The names mirror each other exactly. A
 * marker's optional transform post-processes the typed value — the node's, or the fallback — so the key has
 * one shape with and without OnlineConf; see {@see Transform} for what it may be.
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
     * makes Onlineconf::getString() usable in config/*.php in place of env(). A required module file that is
     * not there is the client's OpenException on either path; an optional one gives get* their defaults.
     *
     * @param string       $method
     * @param array<mixed> $args
     */
    public static function __callStatic($method, $args): mixed
    {
        $app = static::getFacadeApplication();
        if ($app === null || !$app->bound(Module::class)) {
            return self::immediate($method, $args);
        }

        return parent::__callStatic($method, $args);
    }

    /**
     * @param array<mixed> $args
     */
    private static function immediate(string $method, array $args): mixed
    {
        [$type, $optional, $path, $default] = self::read($method, $args);
        if ($type !== null && is_string($path)) {
            EagerReads::record($path, $type, $default, !$optional);
        }

        /** @var callable $callable */
        $callable = [ImmediateModule::module(), $method];

        return $callable(...$args);
    }

    /**
     * What a call reads: the type of a node read (null for any other method), whether it is an optional get*,
     * its path and its default. __callStatic() gets positional arguments under 0, 1, … and named ones under
     * their names — the client's own — so a named argument is looked up by name, even when it is null, and
     * a positional one by position; a positional path with a named default works either way.
     *
     * @param array<mixed> $args
     *
     * @return array{string|null, bool, mixed, mixed}
     */
    private static function read(string $method, array $args): array
    {
        $type = self::READS[$method] ?? null;
        $optional = $type !== null && !str_starts_with($method, 'require');

        return [
            $type,
            $optional,
            array_key_exists('path', $args) ? $args['path'] : ($args[0] ?? null),
            $optional ? (array_key_exists('default', $args) ? $args['default'] : ($args[1] ?? null)) : null,
        ];
    }


    /**
     * A marker for config/*.php read with the client's getString() on every config() call.
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRefString(string $path, ?string $fallback, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_STRING, $fallback, false, Transform::encode($path, $transform));
    }

    /**
     * A marker for config/*.php read with the client's getInt() on every config() call.
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRefInt(string $path, int|string|null $fallback, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_INT, $fallback, false, Transform::encode($path, $transform));
    }

    /**
     * A marker for config/*.php read with the client's getFloat() on every config() call.
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRefFloat(string $path, float|string|null $fallback, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_FLOAT, $fallback, false, Transform::encode($path, $transform));
    }

    /**
     * A marker for config/*.php read with the client's getBool() on every config() call.
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRefBool(string $path, bool|string|null $fallback, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_BOOL, $fallback, false, Transform::encode($path, $transform));
    }

    /**
     * A marker for config/*.php read with the client's getDuration() on every config() call.
     * The node is seconds as a float ("30s", "1m").
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRefDuration(string $path, float|string|null $fallback, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_DURATION, $fallback, false, Transform::encode($path, $transform));
    }

    /**
     * A marker for config/*.php read with the client's getDurationMs() on every config() call.
     * The node is milliseconds as an int.
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRefDurationMs(string $path, int|string|null $fallback, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_DURATION_MS, $fallback, false, Transform::encode($path, $transform));
    }

    /**
     * A marker for config/*.php read with the client's getStrings() on every config() call.
     * The node is a comma-separated value or a JSON array of strings.
     * The fallback is what config() returns while OnlineConf has no such node.
     *
     * @param list<string>|string|null $fallback
     */
    public static function getRefStrings(string $path, array|string|null $fallback, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_STRINGS, $fallback, false, Transform::encode($path, $transform));
    }

    /**
     * A marker for config/*.php read with the client's getArray() on every config() call.
     * The node is a JSON value.
     * The fallback is what config() returns while OnlineConf has no such node.
     *
     * @param array<mixed>|string|null $fallback
     */
    public static function getRefArray(string $path, array|string|null $fallback, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_ARRAY, $fallback, false, Transform::encode($path, $transform));
    }

    /**
     * A marker for config/*.php read with the client's get() on every config() call.
     * The node is the raw value: a string, or decoded JSON.
     * The fallback is what config() returns while OnlineConf has no such node.
     */
    public static function getRef(string $path, mixed $fallback, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_RAW, $fallback, false, Transform::encode($path, $transform));
    }

    /**
     * A marker read with the client's requireString(): the node must exist, or config() throws.
     */
    public static function requireRefString(string $path, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_STRING, null, true, Transform::encode($path, $transform));
    }

    /**
     * A marker read with the client's requireInt(): the node must exist, or config() throws.
     */
    public static function requireRefInt(string $path, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_INT, null, true, Transform::encode($path, $transform));
    }

    /**
     * A marker read with the client's requireFloat(): the node must exist, or config() throws.
     */
    public static function requireRefFloat(string $path, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_FLOAT, null, true, Transform::encode($path, $transform));
    }

    /**
     * A marker read with the client's requireBool(): the node must exist, or config() throws.
     */
    public static function requireRefBool(string $path, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_BOOL, null, true, Transform::encode($path, $transform));
    }

    /**
     * A marker read with the client's requireDuration(): the node must exist, or config() throws.
     */
    public static function requireRefDuration(string $path, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_DURATION, null, true, Transform::encode($path, $transform));
    }

    /**
     * A marker read with the client's requireDurationMs(): the node must exist, or config() throws.
     */
    public static function requireRefDurationMs(string $path, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_DURATION_MS, null, true, Transform::encode($path, $transform));
    }

    /**
     * A marker read with the client's requireStrings(): the node must exist, or config() throws.
     */
    public static function requireRefStrings(string $path, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_STRINGS, null, true, Transform::encode($path, $transform));
    }

    /**
     * A marker read with the client's requireArray(): the node must exist, or config() throws.
     */
    public static function requireRefArray(string $path, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_ARRAY, null, true, Transform::encode($path, $transform));
    }

    /**
     * A marker read with the client's require(): the node must exist, or config() throws.
     */
    public static function requireRef(string $path, ?callable $transform = null): Ref
    {
        return new Ref($path, Ref::TYPE_RAW, null, true, Transform::encode($path, $transform));
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
