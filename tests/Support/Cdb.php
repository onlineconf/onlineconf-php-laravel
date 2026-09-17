<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests\Support;

use RuntimeException;

/**
 * Writes a CDB module the way onlineconf-updater does: into a temporary file, then rename() over the target.
 */
final class Cdb
{
    /**
     * @param array<string, string> $raw path → raw value including the type byte ("s..." or "j...")
     */
    public static function write(string $file, array $raw): void
    {
        $tmp = $file . '.tmp';
        $db = dba_open($tmp, 'n', 'cdb_make');
        if ($db === false) {
            throw new RuntimeException("cannot create {$tmp}");
        }
        foreach ($raw as $key => $value) {
            dba_insert($key, $value, $db);
        }
        dba_close($db);
        if (!rename($tmp, $file)) {
            throw new RuntimeException("cannot rename {$tmp} to {$file}");
        }
    }
}
