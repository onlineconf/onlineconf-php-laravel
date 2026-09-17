<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Onlineconf\Laravel\ModuleManager;
use Onlineconf\Laravel\Tests\Support\Cdb;
use Onlineconf\Module;

final class ServiceProviderTest extends TestCase
{
    public function testConfigDefaultsAreMerged(): void
    {
        self::assertSame(
            ['dir' => null, 'module' => null, 'check_interval' => 5, 'log_channel' => null, 'config_override' => true, 'map' => []],
            $this->config()->get('onlineconf'),
        );
    }

    public function testModuleManagerIsASingleton(): void
    {
        $app = $this->app;
        assert($app !== null);
        self::assertSame($app->make(ModuleManager::class), $app->make(ModuleManager::class));
    }

    public function testModuleIsResolvedFromTheConfiguredDirectory(): void
    {
        $app = $this->app;
        assert($app !== null);
        $this->useModule(['/app/name' => 'sdemo']);

        $module = $app->make(Module::class);
        assert($module instanceof Module);

        self::assertSame('demo', $module->getString('/app/name', ''));
        self::assertSame($module, $app->make(Module::class), 'the manager keeps one instance per file');
        $manager = $app->make(ModuleManager::class);
        assert($manager instanceof ModuleManager);
        self::assertSame($module, $manager->module());
    }

    public function testConfiguredModuleNameIsUsed(): void
    {
        $app = $this->app;
        assert($app !== null);
        $this->useModule(['/app/name' => 'stree']);
        $this->writeModule(['/app/name' => 'sother'], 'other');
        $this->config()->set('onlineconf.module', 'other');

        $module = $app->make(Module::class);
        assert($module instanceof Module);
        self::assertSame('other', $module->getString('/app/name', ''));
    }

    public function testConfigWinsOverTheProcessEnvironment(): void
    {
        $app = $this->app;
        assert($app !== null);
        $envDir = $this->tempDir() . '/env';
        mkdir($envDir);
        Cdb::write($envDir . '/TREE.cdb', ['/app/name' => 'sfrom-env']);
        $this->useModule(['/app/name' => 'sfrom-config']);
        putenv('ONLINECONF_DIR=' . $envDir);

        try {
            $module = $app->make(Module::class);
            assert($module instanceof Module);
            self::assertSame('from-config', $module->getString('/app/name', ''));
        } finally {
            putenv('ONLINECONF_DIR');
            unlink($envDir . '/TREE.cdb');
            rmdir($envDir);
        }
    }

    public function testProcessEnvironmentAppliesWhenConfigIsNull(): void
    {
        $app = $this->app;
        assert($app !== null);
        $this->writeModule(['/app/name' => 'sfrom-env']);
        putenv('ONLINECONF_DIR=' . $this->tempDir());

        try {
            $module = $app->make(Module::class);
            assert($module instanceof Module);
            self::assertSame('from-env', $module->getString('/app/name', ''));
        } finally {
            putenv('ONLINECONF_DIR');
        }
    }

    public function testEmptyConfigValuesCountAsUnset(): void
    {
        $app = $this->app;
        assert($app !== null);
        $this->writeModule(['/app/name' => 'sfrom-env']);
        $this->config()->set('onlineconf.dir', '');
        $this->config()->set('onlineconf.module', '');
        putenv('ONLINECONF_DIR=' . $this->tempDir());

        try {
            $module = $app->make(Module::class);
            assert($module instanceof Module);
            self::assertSame('from-env', $module->getString('/app/name', ''));
        } finally {
            putenv('ONLINECONF_DIR');
        }
    }

    public function testCheckIntervalReachesTheModule(): void
    {
        $app = $this->app;
        assert($app !== null);
        $this->useModule(['/app/name' => 'sfirst']);
        $this->config()->set('onlineconf.check_interval', 0);
        $module = $app->make(Module::class);
        assert($module instanceof Module);
        self::assertSame('first', $module->getString('/app/name', ''));

        $this->writeModule(['/app/name' => 'ssecond']);

        self::assertSame('second', $module->getString('/app/name', ''));
    }

    public function testLargeCheckIntervalKeepsTheLoadedData(): void
    {
        $app = $this->app;
        assert($app !== null);
        $this->useModule(['/app/name' => 'sfirst']);
        $this->config()->set('onlineconf.check_interval', 3600);
        $module = $app->make(Module::class);
        assert($module instanceof Module);
        self::assertSame('first', $module->getString('/app/name', ''));

        $this->writeModule(['/app/name' => 'ssecond']);

        self::assertSame('first', $module->getString('/app/name', ''));
    }
}
