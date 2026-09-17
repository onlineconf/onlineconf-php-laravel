<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Onlineconf\Module;

final class OnlineconfServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/onlineconf.php', 'onlineconf');

        // singletonIf: ConfigOverride::install() may have put a manager into the container already.
        $this->app->singletonIf(ModuleManager::class, static fn (Application $app): ModuleManager => ModuleManagerFactory::fromContainer($app));

        $this->app->bind(Module::class, static function (Application $app): Module {
            $manager = $app->make(ModuleManager::class);
            assert($manager instanceof ModuleManager);

            return $manager->module();
        });
    }
}
