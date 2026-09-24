<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

/**
 * One node read through the facade while config/*.php was loading, before the container knew about the
 * package: what {@see \Onlineconf\Laravel\Console\MapCommand} lists next to the derived map.
 */
final class EagerRead
{
    /**
     * @param string $path    the OnlineConf path that was read
     * @param string $type    one of {@see Ref::TYPES}, from the facade method that was called
     * @param mixed  $default the default passed to the getter, null for a require* call
     */
    public function __construct(
        public readonly string $path,
        public readonly string $type,
        public readonly mixed $default,
    ) {
    }
}
