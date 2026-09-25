<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Closure;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;

/**
 * The post-processing callable of a marker in a form var_export() can write, so markers and the derived map
 * survive config:cache: a Closure as its serialized {@see SerializableClosure} under a "closure" key, a
 * function name or a [class, static method] pair as it is.
 *
 * The closure is not signed by this package. Laravel signs serialized closures itself once the encryption
 * provider has run (with app.key); this package serializes and unserializes them while the configuration is
 * loaded, before any provider, so both ends see the same signer. The configuration cache is a local file.
 *
 * @phpstan-type Encoded string|array{closure: string}|array{0: string, 1: string}
 */
final class Transform
{
    /**
     * @return Encoded|null
     *
     * @throws InvalidArgumentException for a callable config:cache cannot store: an object, or an object method
     */
    public static function encode(string $path, ?callable $transform): string|array|null
    {
        if ($transform === null) {
            return null;
        }
        if ($transform instanceof Closure) {
            return ['closure' => serialize(new SerializableClosure($transform))];
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
     *
     * @throws LogicException when a stored name does not name a callable in this codebase
     */
    public static function decode(string|array $encoded): callable
    {
        if (is_array($encoded) && isset($encoded['closure'])) {
            $closure = unserialize($encoded['closure']);
            assert($closure instanceof SerializableClosure);

            return $closure->getClosure();
        }
        if (!is_callable($encoded)) {
            throw new LogicException(sprintf('OnlineConf marker transform %s is not callable', json_encode($encoded)));
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
