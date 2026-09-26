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
     * @throws InvalidArgumentException for a callable config:cache cannot store — an invokable object, an object
     *                                  method ([$obj, 'm'] or $obj->m(...)), a first-class callable of a function
     *                                  or of a PHP class's static method — naming the form to write instead
     */
    public static function encode(string $path, ?callable $transform): string|array|null
    {
        if ($transform === null) {
            return null;
        }
        if ($transform instanceof Closure) {
            self::assertStorable($path, new ReflectionFunction($transform));

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
            return self::unserializeClosure($encoded['closure'], self::subject($key, $path));
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
     * A first-class callable is a closure over a named function, which has no closure source to store: its
     * name, or its [class, method] pair, is the storable form. One of a user static method survives as it is.
     *
     * @throws InvalidArgumentException naming the storable form to write instead
     */
    private static function assertStorable(string $path, ReflectionFunction $function): void
    {
        $name = $function->getName();
        if (str_starts_with($name, '{closure')) {
            return;
        }
        $scope = $function->getClosureScopeClass();
        $object = $function->getClosureThis();
        if ($object !== null) {
            throw new InvalidArgumentException(sprintf(
                "%s: %s->%s(...) cannot be stored for config:cache; use a static method as [Class::class, 'method']",
                $path,
                get_class($object),
                $name,
            ));
        }
        if ($scope === null) {
            throw new InvalidArgumentException(sprintf("%s: %s(...) cannot be stored for config:cache; write '%s' instead", $path, $name, $name));
        }
        if ($function->isInternal()) {
            throw new InvalidArgumentException(sprintf(
                "%s: %s::%s(...) cannot be stored for config:cache; write [%s::class, '%s'] instead",
                $path,
                $scope->getName(),
                $name,
                $scope->getName(),
                $name,
            ));
        }
    }

    /**
     * unserialize() of a stored closure. Only unserialize()'s own complaint — a notice or warning about the
     * payload — means the payload is not one. The closure source is compiled again in there, so whatever else
     * is raised (a deprecation on a newer PHP, a use variable's warning) goes to the handler that was there
     * before, as if this one were not installed; with none, to PHP's own.
     *
     * @throws LogicException with $notAClosure, or that the closure could not be restored
     */
    private static function unserializeClosure(string $payload, string $subject): Closure
    {
        $notAClosure = sprintf('%s: the stored closure is not a serialized closure', $subject);
        $previous = null;
        $previous = set_error_handler(
            static function (int $level, string $message, string $file = '', int $line = 0) use (&$previous, $notAClosure): bool {
                if (($level & (E_NOTICE | E_WARNING)) !== 0 && str_starts_with($message, 'unserialize()')) {
                    throw new LogicException($notAClosure);
                }

                // A handler that returns nothing has handled the error: only false lets PHP's own run.
                return is_callable($previous) && $previous($level, $message, $file, $line) !== false;
            },
        );
        try {
            $closure = unserialize($payload);
            if (!$closure instanceof UnsignedSerializableClosure) {
                throw new LogicException($notAClosure);
            }

            return $closure->getClosure();
        } catch (Throwable $e) {
            throw $e instanceof LogicException && $e->getMessage() === $notAClosure ? $e : self::notRestored($subject, $e);
        } finally {
            restore_error_handler();
        }
    }

    private static function notRestored(string $subject, Throwable $e): LogicException
    {
        return new LogicException(sprintf('%s: the stored closure could not be restored: %s', $subject, $e->getMessage()), 0, $e);
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
