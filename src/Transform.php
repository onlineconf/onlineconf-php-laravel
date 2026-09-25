<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Closure;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\UnsignedSerializableClosure;
use LogicException;
use ReflectionFunction;

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
            if ($function->isInternal()) {
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
     * @param Encoded $encoded
     * @param string  $key     the config key, for the message
     * @param string  $path    the OnlineConf path, for the message
     *
     * @throws LogicException when the stored form does not give back a callable in this codebase
     */
    public static function decode(string|array $encoded, string $key, string $path): callable
    {
        if (is_array($encoded) && isset($encoded['closure'])) {
            // Checked before unserialize(), which would raise a notice on anything else.
            $prefix = sprintf('O:%d:"%s":', strlen(UnsignedSerializableClosure::class), UnsignedSerializableClosure::class);
            $closure = str_starts_with($encoded['closure'], $prefix) ? unserialize($encoded['closure']) : null;
            if (!$closure instanceof UnsignedSerializableClosure) {
                throw new LogicException(sprintf('%s (%s): the stored closure is not a serialized closure', $key, $path));
            }

            return $closure->getClosure();
        }
        if (!is_callable($encoded)) {
            throw new LogicException(sprintf('%s (%s): %s is not callable', $key, $path, json_encode($encoded)));
        }

        return $encoded;
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
