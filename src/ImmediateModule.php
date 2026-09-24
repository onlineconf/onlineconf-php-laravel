<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Onlineconf\Exception\OpenException;
use Onlineconf\Module;
use Onlineconf\Settings;
use Onlineconf\Source\ArraySource;
use Onlineconf\Source\CdbSource;
use Psr\Log\NullLogger;

/**
 * The module the facade reads from before the service provider registers — while Laravel loads config/*.php,
 * where config('onlineconf.*') does not exist yet. Settings therefore come from the process environment
 * (ONLINECONF_DIR, ONLINECONF_CONFIG, CDB_CONFIG_FILE, then the client's defaults), not from the config file.
 *
 * ONLINECONF_CONFIG_OVERRIDE=false, the kill switch of the config() override, also switches this off: the
 * module is then empty, so get* return their defaults and require* throw NotFoundException.
 *
 * A file that cannot be opened is remembered, so every read costs one failed open per process and not one
 * per call; {@see \Onlineconf\Laravel\Facades\Onlineconf} turns that into a fallback for get*.
 */
final class ImmediateModule
{
    private static ?Module $module = null;

    private static ?OpenException $failure = null;

    /**
     * The module of this process, opened once.
     *
     * @throws OpenException when the module file cannot be opened; the failure is remembered and rethrown
     */
    public static function module(): Module
    {
        if (self::$failure !== null) {
            throw self::$failure;
        }
        if (self::$module !== null) {
            return self::$module;
        }

        try {
            return self::$module = self::open();
        } catch (OpenException $e) {
            self::$failure = $e;

            throw $e;
        }
    }

    /**
     * Forgets the module, so the next read resolves the environment again; for tests.
     */
    public static function flush(): void
    {
        self::$module = null;
        self::$failure = null;
    }

    private static function open(): Module
    {
        $logger = new NullLogger();
        if (!(bool) env('ONLINECONF_CONFIG_OVERRIDE', true)) {
            return new Module(new ArraySource([], 'off'), $logger, 0);
        }
        $settings = Settings::resolve(getenv(), null, null, $logger);

        return new Module(new CdbSource($settings->fileName($settings->module)), $logger, Module::DEFAULT_CHECK_INTERVAL);
    }
}
