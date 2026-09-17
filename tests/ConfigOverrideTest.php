<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Onlineconf\Laravel\Config\OverridingRepository;
use Onlineconf\Laravel\ConfigOverride;
use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Laravel\ModuleManager;

final class ConfigOverrideTest extends TestCase
{
    public function testInstallReplacesTheRepositoryAndReadsMappedKeys(): void
    {
        $this->useModule(['/app/name' => 'sFrom OnlineConf']);
        $this->config()->set('app.name', 'From config');
        $this->config()->set('onlineconf.map', ['app.name' => '/app/name', 'app.missing' => '/app/missing']);

        ConfigOverride::install($this->application());

        $config = $this->application()->make(Repository::class);
        self::assertInstanceOf(OverridingRepository::class, $config);
        self::assertSame($config, $this->application()->make('config'));
        self::assertSame('From OnlineConf', $config->get('app.name'));
        self::assertSame('From OnlineConf', config('app.name'));
        self::assertNull(config('app.missing'));
        self::assertSame(['app.name' => '/app/name', 'app.missing' => '/app/missing'], config('onlineconf.map'), 'the rest of the configuration is intact');
    }

    public function testManagerIsSharedWithTheProviderSoFakesReachConfig(): void
    {
        $this->useModule(['/app/name' => 'sreal']);
        $this->config()->set('onlineconf.map', ['app.name' => '/app/name']);

        ConfigOverride::install($this->application());

        self::assertSame('real', config('app.name'));
        self::assertSame($this->application()->make(ModuleManager::class), $this->application()->make(ModuleManager::class));

        Onlineconf::fake(['/app/name' => 'fake']);

        self::assertSame('fake', config('app.name'));
        self::assertSame('fake', Onlineconf::getString('/app/name', ''));
    }

    public function testKillSwitchLeavesTheRepositoryAlone(): void
    {
        $this->config()->set('onlineconf.map', ['app.name' => '/app/name']);
        $this->config()->set('onlineconf.config_override', false);
        $before = $this->config();

        ConfigOverride::install($this->application());

        self::assertSame($before, $this->config());
    }

    public function testEmptyMapLeavesTheRepositoryAlone(): void
    {
        $before = $this->config();

        ConfigOverride::install($this->application());

        self::assertSame($before, $this->config());
    }

    public function testInvalidMapEntriesAreIgnored(): void
    {
        $this->useModule(['/app/name' => 'sFrom OnlineConf', '/junk' => 'sjunk']);
        $this->config()->set('onlineconf.map', ['app.name' => '/app/name', 0 => '/junk', 'app.other' => 5, '' => '/junk']);

        ConfigOverride::install($this->application());

        self::assertSame('From OnlineConf', config('app.name'));
        self::assertFalse($this->config()->has('app.other'));
        self::assertFalse($this->config()->has('0'));
    }

    public function testRegisterHooksIntoTheBootstrapSequence(): void
    {
        $base = $this->tempDir() . '/app';
        mkdir($base . '/config', 0o700, true);
        file_put_contents(
            $base . '/config/app.php',
            "<?php return ['name' => 'From config', 'env' => 'testing', 'timezone' => 'UTC', 'locale' => 'en', 'fallback_locale' => 'en', 'providers' => [], 'aliases' => []];",
        );
        file_put_contents(
            $base . '/config/logging.php',
            "<?php return ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \\Monolog\\Handler\\NullHandler::class]]];",
        );
        $this->writeModule(['/app/name' => 'sFrom OnlineConf']);
        file_put_contents(
            $base . '/config/onlineconf.php',
            sprintf(
                "<?php return ['dir' => %s, 'module' => null, 'check_interval' => 0, 'log_channel' => null, 'config_override' => true, 'map' => ['app.name' => '/app/name']];",
                var_export($this->tempDir(), true),
            ),
        );

        $app = new Application($base);
        try {
            ConfigOverride::register($app);
            $app->bootstrapWith([LoadConfiguration::class]);

            $config = $app->make(Repository::class);
            self::assertInstanceOf(OverridingRepository::class, $config);
            self::assertSame('From OnlineConf', $config->get('app.name'));
            self::assertSame('testing', $config->get('app.env'));
        } finally {
            $app->flush();
            Container::setInstance($this->application());
        }
    }
}
