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
        $settings = Settings::resolve(
            getenv(),
            self::stringOrNull($config->get('onlineconf.dir')),
            self::stringOrNull($config->get('onlineconf.module')),
            $logger,
        );
        $interval = $config->get('onlineconf.check_interval', Module::DEFAULT_CHECK_INTERVAL);

        return new ModuleManager($settings, $logger, is_numeric($interval) ? (int) $interval : Module::DEFAULT_CHECK_INTERVAL);
    }

    /**
     * The configured log channel, or the application's default logger.
     */
    public static function logger(Application $app, mixed $channel): LoggerInterface
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
     * Empty strings count as unset, as empty environment variables do in the client.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
