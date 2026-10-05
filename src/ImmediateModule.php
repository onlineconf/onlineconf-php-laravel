<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Onlineconf\Exception\OpenException;
use Onlineconf\Module;
use Onlineconf\Settings;
use Psr\Log\NullLogger;

/**
 * The module the facade reads from before the service provider registers — while Laravel loads config/*.php,
 * where config('onlineconf.*') does not exist yet. Settings therefore come from the environment as env() sees
 * it ({@see ProcessEnvironment}: ONLINECONF_DIR, ONLINECONF_CONFIG, CDB_CONFIG_FILE, then the client's
 * defaults), not from the config file.
 *
 * ONLINECONF_REQUIRED comes from there too: a required module file that is not there fails the boot at the
 * first read; an optional one gives get* their defaults until the file appears.
 */
final class ImmediateModule
{
    private static ?Module $module = null;

    /**
     * The module of this process, opened once with the client's {@see Module::fromFile()}: required or optional
     * as ONLINECONF_REQUIRED in the process environment says.
     *
     * @throws OpenException when a required module file is not there, or cannot be opened
     */
    public static function module(): Module
    {
        return self::$module ??= self::open();
    }

    /**
     * Forgets the module, so the next read resolves the environment again; for tests.
     */
    public static function flush(): void
    {
        self::$module = null;
    }

    private static function open(): Module
    {
        $logger = new NullLogger();
        $settings = Settings::resolve(ProcessEnvironment::variables(), null, null, $logger);

        return Module::fromFile($settings->fileName($settings->module), $settings->required, $logger, Module::DEFAULT_CHECK_INTERVAL);
    }
}
