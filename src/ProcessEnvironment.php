<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Illuminate\Support\Env;

/**
 * The environment the client resolves its settings from (ONLINECONF_DIR, ONLINECONF_CONFIG, CDB_CONFIG_FILE,
 * ONLINECONF_REQUIRED), as Laravel's env() sees it: $_SERVER and $_ENV first, getenv() when putenv() is on. An
 * application with putenv() off — Env::disablePutenv(), as Testbench does — keeps its .env out of getenv(), and
 * the immediate reads would then disagree with "onlineconf.*", which env() fills.
 *
 * @internal
 */
final class ProcessEnvironment
{
    private const VARIABLES = ['ONLINECONF_DIR', 'ONLINECONF_CONFIG', 'CDB_CONFIG_FILE', 'ONLINECONF_REQUIRED'];

    /**
     * @return array<string, string> getenv(), with the client's variables as env() reads them
     */
    public static function variables(): array
    {
        $variables = getenv();
        $repository = Env::getRepository();
        foreach (self::VARIABLES as $name) {
            $value = $repository->get($name);
            if ($value !== null) {
                $variables[$name] = $value;
            }
        }

        return $variables;
    }
}
