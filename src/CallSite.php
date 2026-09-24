<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

/**
 * The application frames of the current stack: who read the value.
 */
final class CallSite
{
    /**
     * Up to $limit "file:line" frames outside vendor/ and outside this package, innermost first — the
     * application code that called config() or read a node while config/*.php was loading.
     *
     * @return list<string>
     */
    public static function frames(int $limit = 5): array
    {
        $package = __DIR__ . \DIRECTORY_SEPARATOR;
        $vendor = \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR;
        $frames = [];
        foreach (debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null || str_starts_with($file, $package) || str_contains($file, $vendor)) {
                continue;
            }
            assert(isset($frame['line']));
            $frames[] = $file . ':' . $frame['line'];
        }

        return array_slice($frames, 0, $limit);
    }
}
