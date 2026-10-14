<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Onlineconf\Module;
use Onlineconf\Settings;
use Psr\Log\LoggerInterface;

/**
 * Builds the {@see ModuleManager} from the "onlineconf" configuration; shared by the service provider and
 * {@see ConfigOverride}, which needs the manager before providers are registered.
 */
final class ModuleManagerFactory
{
    public static function fromContainer(Application $app): ModuleManager
    {
        $config = $app->make(Repository::class);
        assert($config instanceof Repository);
        $logger = self::logger($app, $config->get('onlineconf.log_channel'));
        $env = ProcessEnvironment::variables();
        // Decided before the settings resolve: a disabled client reads no client config file either
        $enabled = self::switchedOn($config->get('onlineconf.enabled'));
        if ($enabled !== null) {
            $env['ONLINECONF_ENABLED'] = $enabled ? 'true' : 'false';
        }
        $settings = Settings::resolve(
            $env,
            self::stringOrNull($config->get('onlineconf.dir')),
            self::stringOrNull($config->get('onlineconf.module')),
            $logger,
        );
        $required = self::switchedOn($config->get('onlineconf.required'));
        if ($required !== null) {
            $settings = new Settings($settings->dir, $settings->module, $required, $settings->enabled);
        }
        $interval = $config->get('onlineconf.check_interval', Module::DEFAULT_CHECK_INTERVAL);

        return new ModuleManager($settings, $logger, is_numeric($interval) ? (int) $interval : Module::DEFAULT_CHECK_INTERVAL);
    }

    /**
     * The configured log channel, or the application's default logger, resolved at the first record
     * ({@see LazyLogger}): taken while the configuration loads, it is mostly used long after.
     */
    public static function logger(Application $app, mixed $channel): LoggerInterface
    {
        return new LazyLogger(static fn (): LoggerInterface => self::channel($app, $channel));
    }

    private static function channel(Application $app, mixed $channel): LoggerInterface
    {
        if (is_string($channel) && $channel !== '') {
            $logManager = $app->make(LogManager::class);
            assert($logManager instanceof LogManager);

            return $logManager->channel($channel);
        }

        $logger = $app->make(LoggerInterface::class);
        assert($logger instanceof LoggerInterface);

        return $logger;
    }

    /**
     * "onlineconf.required" and "onlineconf.enabled" — env('ONLINECONF_REQUIRED') and env('ONLINECONF_ENABLED'),
     * which a config:cache'd process keeps — read as the client reads the variables: only false/"false"/"0"
     * switch them off; null or an empty value leave the decision to the process environment. A configured value
     * wins over the variable, as a cached configuration wins over .env.
     */
    private static function switchedOn(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return !($value === false || $value === 0 || (is_string($value) && in_array(strtolower($value), ['false', '0'], true)));
    }

    /**
     * Empty strings count as unset, as empty environment variables do in the client.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
