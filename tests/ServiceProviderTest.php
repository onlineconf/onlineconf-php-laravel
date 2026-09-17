<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Onlineconf\Laravel\ModuleManager;
use Onlineconf\Laravel\OnlineconfServiceProvider;
use Onlineconf\Laravel\Tests\Support\Cdb;
use Onlineconf\Module;

final class ServiceProviderTest extends TestCase
{
    public function testBootDoesNothingOutsideTheConsole(): void
    {
        $app = $this->createMock(Application::class);
        $app->method('runningInConsole')->willReturn(false);
        $app->expects(self::never())->method('configPath');

        (new OnlineconfServiceProvider($app))->boot();
    }

    public function testConfigDefaultsAreMerged(): void
    {
        self::assertSame(
            ['dir' => null, 'module' => null, 'check_interval' => 5, 'log_channel' => null, 'config_override' => true, 'map' => []],
            $this->config()->get('onlineconf'),
        );
    }

    public function testModuleManagerIsASingleton(): void
    {
        self::assertSame($this->application()->make(ModuleManager::class), $this->application()->make(ModuleManager::class));
    }

    public function testModuleIsResolvedFromTheConfiguredDirectory(): void
    {
        $this->useModule(['/app/name' => 'sdemo']);

        $module = $this->application()->make(Module::class);
        assert($module instanceof Module);

        self::assertSame('demo', $module->getString('/app/name', ''));
        self::assertSame($module, $this->application()->make(Module::class), 'the manager keeps one instance per file');
        $manager = $this->application()->make(ModuleManager::class);
        assert($manager instanceof ModuleManager);
        self::assertSame($module, $manager->module());
    }

    public function testConfiguredModuleNameIsUsed(): void
    {
        $this->useModule(['/app/name' => 'stree']);
        $this->writeModule(['/app/name' => 'sother'], 'other');
        $this->config()->set('onlineconf.module', 'other');

        $module = $this->application()->make(Module::class);
        assert($module instanceof Module);
        self::assertSame('other', $module->getString('/app/name', ''));
    }

    public function testConfigWinsOverTheProcessEnvironment(): void
    {
        $envDir = $this->tempDir() . '/env';
        mkdir($envDir);
        Cdb::write($envDir . '/TREE.cdb', ['/app/name' => 'sfrom-env']);
        $this->useModule(['/app/name' => 'sfrom-config']);
        putenv('ONLINECONF_DIR=' . $envDir);

        try {
            $module = $this->application()->make(Module::class);
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
        $this->writeModule(['/app/name' => 'sfrom-env']);
        putenv('ONLINECONF_DIR=' . $this->tempDir());

        try {
            $module = $this->application()->make(Module::class);
            assert($module instanceof Module);
            self::assertSame('from-env', $module->getString('/app/name', ''));
        } finally {
            putenv('ONLINECONF_DIR');
        }
    }

    public function testEmptyConfigValuesCountAsUnset(): void
    {
        $this->writeModule(['/app/name' => 'sfrom-env']);
        $this->config()->set('onlineconf.dir', '');
        $this->config()->set('onlineconf.module', '');
        putenv('ONLINECONF_DIR=' . $this->tempDir());

        try {
            $module = $this->application()->make(Module::class);
            assert($module instanceof Module);
            self::assertSame('from-env', $module->getString('/app/name', ''));
        } finally {
            putenv('ONLINECONF_DIR');
        }
    }

    public function testCheckIntervalReachesTheModule(): void
    {
        $this->useModule(['/app/name' => 'sfirst']);
        $this->config()->set('onlineconf.check_interval', 0);
        $module = $this->application()->make(Module::class);
        assert($module instanceof Module);
        self::assertSame('first', $module->getString('/app/name', ''));

        $this->writeModule(['/app/name' => 'ssecond']);

        self::assertSame('second', $module->getString('/app/name', ''));
    }

    public function testLargeCheckIntervalKeepsTheLoadedData(): void
    {
        $this->useModule(['/app/name' => 'sfirst']);
        $this->config()->set('onlineconf.check_interval', 3600);
        $module = $this->application()->make(Module::class);
        assert($module instanceof Module);
        self::assertSame('first', $module->getString('/app/name', ''));

        $this->writeModule(['/app/name' => 'ssecond']);

        self::assertSame('first', $module->getString('/app/name', ''));
    }

    public function testConfigIsPublishable(): void
    {
        $target = $this->application()->configPath('onlineconf.php');
        self::assertFileDoesNotExist($target);

        try {
            self::assertSame(0, Artisan::call('vendor:publish', ['--tag' => 'onlineconf-config']));
            self::assertFileEquals(dirname(__DIR__) . '/config/onlineconf.php', $target);
        } finally {
            if (is_file($target)) {
                unlink($target);
            }
        }
    }
}
