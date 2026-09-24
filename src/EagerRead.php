<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

/**
 * One node read through the facade while config/*.php was loading, before the container knew about the
 * package: the input of {@see \Onlineconf\Laravel\Console\MapCommand} and of the on_missing report.
 */
final class EagerRead
{
    /**
     * @param string       $path    the OnlineConf path that was read
     * @param string       $type    one of {@see Ref::TYPES}, from the facade method that was called
     * @param mixed        $default the default passed to the getter, null for a require* call
     * @param bool         $missing OnlineConf had no such node, so the default was used (or require* threw)
     * @param string       $module  name of the module the read went to
     * @param list<string> $trace   up to five "file:line" frames outside vendor/ and this package
     */
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly mixed $default,
        public readonly bool $missing,
        public readonly string $module,
        public readonly array $trace,
    ) {
    }
}
