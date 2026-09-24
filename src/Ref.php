<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

/**
 * A reference to an OnlineConf node, written in config/*.php in place of the value:
 *
 *     'debug' => \Onlineconf\Laravel\Facades\Onlineconf::refBool('/my/app/debug', (bool) env('APP_DEBUG')),
 *
 * {@see ConfigOverride::install()} takes every marker out of the loaded configuration, leaves the fallback
 * in its place and adds the node to the map the config() override reads from. The type is what the marker
 * declares, never guessed from the fallback; a marker without a fallback means the node must exist.
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

    /** @var list<string> every type a marker, and an explicit map entry, may declare */
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
     * @param bool   $required the node must exist: the client's exception is thrown instead of a fallback
     */
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly mixed $fallback = null,
        public readonly bool $required = false,
    ) {
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

        return new self(
            is_string($path) ? $path : '',
            is_string($type) ? $type : self::TYPE_RAW,
            $state['fallback'] ?? null,
            (bool) ($state['required'] ?? false),
        );
    }
}
