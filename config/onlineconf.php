<?php

declare(strict_types=1);

return [
    // Directory with module files. null: the client's own resolution chain applies — the process
    // environment (ONLINECONF_DIR, ONLINECONF_CONFIG, CDB_CONFIG_FILE), then /usr/local/etc/onlineconf.yaml,
    // then /usr/local/etc/onlineconf. Values from .env are visible only through this config key.
    'dir' => env('ONLINECONF_DIR'),

    // Default module: a name ("TREE" -> "<dir>/TREE.cdb") or a file path. null: the client's default
    // ("TREE", or the module named by CDB_CONFIG_FILE in the process environment).
    'module' => env('ONLINECONF_MODULE'),

    // Seconds between stat() checks for updates; 0 checks on every access.
    'check_interval' => env('ONLINECONF_CHECK_INTERVAL', 5),

    // Log channel for the client's warnings and reload messages. null: the application's default logger.
    'log_channel' => env('ONLINECONF_LOG_CHANNEL'),

    // Kill switch of the config() override (see README, "Overriding config() values"). It has an effect only when
    // Onlineconf\Laravel\ConfigOverride::register($app) is called in bootstrap/app.php.
    'config_override' => (bool) env('ONLINECONF_CONFIG_OVERRIDE', true),

    // Laravel config key => OnlineConf path. A mapped key is read from OnlineConf and falls back to the value
    // below it in config/*.php when OnlineConf has no such key. Empty: the override does nothing.
    'map' => [
        // 'services.mailer.host' => '/my/service/mailer/host',
    ],
];
