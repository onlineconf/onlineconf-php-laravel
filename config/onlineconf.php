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

    // Kill switch of the config() override (see README, "Overriding config() values"): markers stop reading
    // from OnlineConf and config() returns their fallbacks. It has an effect only when
    // Onlineconf\Laravel\ConfigOverride::register($app) is called in bootstrap/app.php. The same variable, read
    // from the process environment, switches off the immediate reads (Onlineconf::getString() in config/*.php):
    // they then return their defaults, and require* throw NotFoundException.
    'config_override' => (bool) env('ONLINECONF_CONFIG_OVERRIDE', true),

    // Called once per config key and process when a mapped key is absent from OnlineConf and config() falls back
    // to the value below. null: silent fallback. A class name is resolved from the container and invoked with
    // an Onlineconf\Laravel\MissingValue (config key, OnlineConf path, fallback, module, call site); a Closure
    // works too but not together with config:cache.
    'on_missing' => null,

    // Extra "Laravel config key => OnlineConf node" entries, for keys whose config/*.php you would rather not
    // touch. The map the override uses is derived from the Onlineconf::getRef*() markers in config/*.php and
    // merged with this one — a marker wins over an entry for the same key — and written back here, so
    // config('onlineconf.map') and "php artisan onlineconf:map" always show what is actually read.
    // Both formats are accepted:
    //     'services.mailer.host' => '/my/service/mailer/host',                     // read by the type of the fallback
    //     'services.mailer.port' => ['path' => '/my/service/mailer/port', 'type' => 'int', 'required' => false],
    'map' => [
    ],
];
