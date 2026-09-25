<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use LogicException;

/**
 * A reference to an OnlineConf node, written in config/*.php in place of the value:
 *
 *     'debug' => \Onlineconf\Laravel\Facades\Onlineconf::getRefBool('/my/app/debug', (bool) env('APP_DEBUG')),
 *
 * {@see ConfigOverride::install()} takes every marker out of the loaded configuration, leaves the fallback
 * in its place and adds the node to the map the config() override reads from. The type is what the marker
 * declares, never guessed from the fallback; a requireRef*() marker means the node must exist.
 *
 * A transform post-processes the value: the node value when OnlineConf has it, the fallback otherwise, so the
 * key has one shape either way. It is kept in its stored form ({@see Transform}), so a marker can go through
 * var_export() like any other configuration value.
 *
 * @phpstan-import-type Encoded from Transform
 */
final class Ref
{
    public const TYPE_STRING = 'string';
    public const TYPE_INT = 'int';
    public const TYPE_FLOAT = 'float';
    public const TYPE_BOOL = 'bool';
    public const TYPE_DURATION = 'duration';
    public const TYPE_DURATION_MS = 'duration_ms';
    public const TYPE_STRINGS = 'strings';
    public const TYPE_ARRAY = 'array';
    public const TYPE_RAW = 'raw';

    /** @var list<string> every type a marker may declare */
    public const TYPES = [
        self::TYPE_STRING,
        self::TYPE_INT,
        self::TYPE_FLOAT,
        self::TYPE_BOOL,
        self::TYPE_DURATION,
        self::TYPE_DURATION_MS,
        self::TYPE_STRINGS,
        self::TYPE_ARRAY,
        self::TYPE_RAW,
    ];

    /**
     * @param string $path     the OnlineConf path, e.g. "/my/app/debug"
     * @param string $type     one of {@see Ref::TYPES}; picks the getter the override calls
     * @param mixed  $fallback the value config() returns when OnlineConf has no such node
     * @param bool   $required from requireRef*(): the node must exist, and the client's exception is thrown
     *                        instead of a fallback
     * @param Encoded|null $transform the post-processing callable in its stored form, see {@see Transform::encode()}
     */
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly mixed $fallback = null,
        public readonly bool $required = false,
        public readonly string|array|null $transform = null,
    ) {
    }

    /**
     * The node's value right now, through the transform: the facade's immediate read of the declared type —
     * get*() with the fallback, require*() for a required marker — so it works after boot and while
     * config/*.php loads (recorded as an immediate read). A node or module that is not there gives the
     * fallback, or the client's exception for a required marker; an unparsable node gives the client's warning
     * and the fallback.
     *
     * Nothing is memoised here: the client caches the raw values per module version, and a stored closure is
     * unserialized on every call. A marker read this way is not in the derived map unless it also sits in a
     * config file, so onlineconf:map does not list the read.
     *
     * @throws \Onlineconf\Exception\OnlineconfException for a required marker whose node or module is missing
     * @throws \RuntimeException                          when the transform throws
     */
    public function value(): mixed
    {
        return MarkerReader::read($this);
    }

    /**
     * A marker is not a value: PHP would turn it into "1" or true and the mistake would be invisible. This
     * is the case for Onlineconf::get*(), which reads the node right away and hands over a plain value.
     *
     * @throws LogicException always
     */
    public function __toString(): string
    {
        throw new LogicException(sprintf(
            'Onlineconf::getRef*() marker for %s used as a value; use Onlineconf::get*() when the config transforms the value',
            $this->path,
        ));
    }

    /**
     * Rebuilds a marker from var_export() output: config:cache writes the configuration with var_export(),
     * and a marker can reach it when the override is not installed.
     *
     * @param array<array-key, mixed> $state
     */
    public static function __set_state(array $state): self
    {
        $path = $state['path'] ?? '';
        $type = $state['type'] ?? self::TYPE_RAW;
        $transform = $state['transform'] ?? null;

        return new self(
            is_string($path) ? $path : '',
            is_string($type) ? $type : self::TYPE_RAW,
            $state['fallback'] ?? null,
            (bool) ($state['required'] ?? false),
            Transform::isEncoded($transform) ? $transform : null,
        );
    }
}
