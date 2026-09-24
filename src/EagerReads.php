<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

/**
 * Process-wide registry of the reads the facade served before the service provider registered — the
 * immediate mode, used by config/*.php that calls Onlineconf::getString() instead of env().
 *
 * The registry lives as long as the process: {@see ConfigOverride::install()} reports the misses to the
 * on_missing handler, and "php artisan onlineconf:map" lists everything that was read.
 */
final class EagerReads
{
    /** @var list<EagerRead> */
    private static array $reads = [];

    private static int $reported = 0;

    private static ?string $openError = null;

    private static bool $openErrorReported = false;

    public static function record(string $path, string $type, mixed $default, bool $missing, string $module): void
    {
        self::$reads[] = new EagerRead($path, $type, $default, $missing, $module, CallSite::frames());
    }

    /**
     * @return list<EagerRead>
     */
    public static function all(): array
    {
        return self::$reads;
    }

    /**
     * The reads nobody has been told about yet; they count as reported afterwards, so a second install() in
     * the same process does not report them twice. The registry is trimmed to that batch: a test suite that
     * boots one application per test would otherwise collect the reads of every boot.
     *
     * @return list<EagerRead>
     */
    public static function rotate(): array
    {
        $unreported = array_slice(self::$reads, self::$reported);
        if ($unreported !== []) {
            self::$reads = $unreported;
        }
        self::$reported = count(self::$reads);

        return $unreported;
    }

    /**
     * The module file could not be opened while config/*.php was loading: get* fell back to their defaults.
     * Only the first failure is kept — they all have the same cause.
     */
    public static function openFailed(string $message): void
    {
        self::$openError ??= $message;
    }

    /**
     * The open failure of this process, if there was one.
     */
    public static function openError(): ?string
    {
        return self::$openError;
    }

    /**
     * The open failure if nobody has been told about it yet; it counts as reported afterwards, so a second
     * install() in the same process does not log it again.
     */
    public static function unreportedOpenError(): ?string
    {
        if (self::$openErrorReported) {
            return null;
        }
        self::$openErrorReported = true;

        return self::$openError;
    }

    /**
     * Forgets everything; for tests.
     */
    public static function flush(): void
    {
        self::$reads = [];
        self::$reported = 0;
        self::$openError = null;
        self::$openErrorReported = false;
    }
}
