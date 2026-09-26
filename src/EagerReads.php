<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

/**
 * Registry of the reads the facade served before the service provider registered — the immediate mode, used
 * by config/*.php that calls Onlineconf::getString() instead of env(). "php artisan onlineconf:map" lists
 * them next to the markers.
 */
final class EagerReads
{
    /** @var list<EagerRead> */
    private static array $reads = [];

    private static int $kept = 0;

    public static function record(string $path, string $type, mixed $default, bool $required = false): void
    {
        self::$reads[] = new EagerRead($path, $type, $default, $required);
    }

    /**
     * @return list<EagerRead>
     */
    public static function all(): array
    {
        return self::$reads;
    }

    /**
     * Keeps only the reads of the configuration load that has just finished — none, if it made none: a
     * process that boots one application per test would otherwise collect the reads of every boot.
     */
    public static function trim(): void
    {
        self::$reads = array_slice(self::$reads, self::$kept);
        self::$kept = count(self::$reads);
    }

    /**
     * Forgets everything; for tests.
     */
    public static function flush(): void
    {
        self::$reads = [];
        self::$kept = 0;
    }
}
