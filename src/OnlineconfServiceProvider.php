<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Onlineconf\Exception\OpenException;
use Onlineconf\Laravel\Console\GetCommand;
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

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__ . '/../config/onlineconf.php' => $this->app->configPath('onlineconf.php')], 'onlineconf-config');
        $this->commands([GetCommand::class]);

        AboutCommand::add('OnlineConf', fn (): array => $this->about());
    }

    /**
     * @return array<string, string>
     */
    private function about(): array
    {
        $manager = $this->app->make(ModuleManager::class);
        assert($manager instanceof ModuleManager);
        $settings = $manager->settings();

        try {
            $version = $manager->module()->version();
        } catch (OpenException $e) {
            $version = 'error: ' . $e->getMessage();
        }

        return [
            'Directory' => $settings->dir,
            'Module' => $settings->fileName($settings->module),
            'Version' => $version,
        ];
    }
}
