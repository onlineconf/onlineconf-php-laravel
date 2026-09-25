<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Closure;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\UnsignedSerializableClosure;
use LogicException;
use ReflectionFunction;
use RuntimeException;
use Throwable;

/**
 * The post-processing callable of a marker in a form var_export() can write, so markers and the derived map
 * survive config:cache: a Closure as its serialized {@see UnsignedSerializableClosure} under a "closure" key,
 * a function name or a [class, static method] pair as it is.
 *
 * The stored closure is unsigned: the configuration cache is trusted local PHP, and a signature would only tie
 * the cache to the APP_KEY it was written with.
 *
 * @phpstan-type Encoded string|array{closure: string}|array{0: string, 1: string}
 */
final class Transform
{
    /**
     * @return Encoded|null
     *
     * @throws InvalidArgumentException for a callable config:cache cannot store: an object, an object method,
     *                                  or a first-class callable of a PHP function (store its name instead)
     */
    public static function encode(string $path, ?callable $transform): string|array|null
    {
        if ($transform === null) {
            return null;
        }
        if ($transform instanceof Closure) {
            $function = new ReflectionFunction($transform);
            // A first-class callable of a function — PHP's (trim(...)) or the application's — has no closure
            // source to store; its name is the storable form. One of a user static method survives as it is.
            $named = !str_starts_with($function->getName(), '{closure');
            if ($named && ($function->isInternal() || $function->getClosureScopeClass() === null)) {
                throw new InvalidArgumentException(sprintf(
                    "%s: %s(...) cannot be stored for config:cache; write '%s' instead",
                    $path,
                    $function->getName(),
                    $function->getName(),
                ));
            }

            return ['closure' => serialize(SerializableClosure::unsigned($transform))];
        }
        if (self::isEncoded($transform)) {
            return $transform;
        }

        throw new InvalidArgumentException(sprintf(
            'The transform of the OnlineConf marker for %s must be a Closure, a function name or a [class, method] pair;'
            . ' config:cache cannot store %s',
            $path,
            get_debug_type($transform),
        ));
    }

    /**
     * @param Encoded     $encoded
     * @param string|null $key     the config key, for the message; null for a marker read on its own
     * @param string      $path    the OnlineConf path, for the message
     *
     * @throws LogicException when the stored form does not give back a callable in this codebase
     */
    public static function decode(string|array $encoded, ?string $key, string $path): callable
    {
        if (is_array($encoded) && isset($encoded['closure'])) {
            $notAClosure = sprintf('%s: the stored closure is not a serialized closure', self::subject($key, $path));
            // unserialize() reports a corrupt payload with a notice; turn it into the exception.
            set_error_handler(static function () use ($notAClosure): never {
                throw new LogicException($notAClosure);
            });
            try {
                $closure = unserialize($encoded['closure']);
            } finally {
                restore_error_handler();
            }
            if (!$closure instanceof UnsignedSerializableClosure) {
                throw new LogicException($notAClosure);
            }

            return $closure->getClosure();
        }
        if (!is_callable($encoded)) {
            throw new LogicException(sprintf('%s: %s is not callable', self::subject($key, $path), json_encode($encoded)));
        }

        return $encoded;
    }

    /**
     * Runs the transform. Whatever it throws is a programming error in config/*.php, not a missing value, so it
     * propagates — named after the config key and the path, since the stack trace points into this package.
     *
     * @param string|null $key the config key; null for a marker read on its own
     *
     * @throws RuntimeException wrapping what the transform threw
     */
    public static function apply(callable $transform, mixed $value, ?string $key, string $path): mixed
    {
        try {
            return $transform($value);
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf('OnlineConf transform of %s failed: %s', self::subject($key, $path), $e->getMessage()),
                previous: $e,
            );
        }
    }

    /**
     * "app.hosts (/my/hosts)", or just the path when there is no config key.
     */
    private static function subject(?string $key, string $path): string
    {
        return $key === null ? $path : sprintf('%s (%s)', $key, $path);
    }

    /**
     * Whether a value — a callable, or what was read back from the configuration — is in the stored form.
     *
     * @phpstan-assert-if-true Encoded $value
     */
    public static function isEncoded(mixed $value): bool
    {
        if (is_string($value)) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }
        if (count($value) === 1 && isset($value['closure'])) {
            return is_string($value['closure']);
        }

        return array_is_list($value) && count($value) === 2 && is_string($value[0]) && is_string($value[1]);
    }
}
