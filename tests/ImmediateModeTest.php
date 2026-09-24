<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\OpenException;
use Onlineconf\Laravel\ConfigOverride;
use Onlineconf\Laravel\EagerReads;
use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Laravel\ImmediateModule;
use Onlineconf\Laravel\Ref;

/**
 * The facade while config/*.php is loading: the service provider has not registered Module::class yet, so
 * reads go to the process environment and are recorded for later reporting.
 */
final class ImmediateModeTest extends TestCase
{
    private ?Application $bare = null;

    protected function setUp(): void
    {
        parent::setUp();
        EagerReads::flush();
        ImmediateModule::flush();
    }

    protected function tearDown(): void
    {
        putenv('ONLINECONF_DIR');
        putenv('ONLINECONF_CONFIG_OVERRIDE');
        EagerReads::flush();
        ImmediateModule::flush();
        if ($this->bare !== null) {
            $this->bare->flush();
            $this->bare = null;
        }
        Facade::setFacadeApplication($this->application());
        Container::setInstance($this->application());
        parent::tearDown();
    }

    /**
     * An application as it is while LoadConfiguration runs: nothing of this package is registered.
     */
    private function beforeProviders(bool $withModule = true): Application
    {
        if ($withModule) {
            $this->writeModule([
                '/app/name' => 'sFrom OnlineConf',
                '/app/workers' => 's8',
            ]);
        }
        putenv('ONLINECONF_DIR=' . $this->tempDir());
        $this->bare = new Application($this->tempDir());
        Facade::setFacadeApplication($this->bare);

        return $this->bare;
    }

    public function testGettersReadTheModuleOfTheProcessEnvironment(): void
    {
        $app = $this->beforeProviders();
        self::assertFalse($app->bound(\Onlineconf\Module::class), 'the provider has not run yet');

        self::assertSame('From OnlineConf', Onlineconf::getString('/app/name', 'dflt'));
        self::assertSame(8, Onlineconf::requireInt('/app/workers'));
        self::assertSame('dflt', Onlineconf::getString('/app/gone', 'dflt'));
        self::assertSame('TREE', Onlineconf::name(), 'a method that is not a node read still works');
    }

    public function testEveryReadIsRecorded(): void
    {
        $this->beforeProviders();

        Onlineconf::getString('/app/name', 'dflt');
        Onlineconf::getInt('/app/gone', 7);
        $line = __LINE__ - 1;
        Onlineconf::name();

        $reads = EagerReads::all();
        self::assertCount(2, $reads, 'only node reads are recorded');
        self::assertSame('/app/name', $reads[0]->path);
        self::assertSame(Ref::TYPE_STRING, $reads[0]->type);
        self::assertSame('dflt', $reads[0]->default);
        self::assertFalse($reads[0]->missing);
        self::assertSame('TREE', $reads[0]->module);
        self::assertSame('/app/gone', $reads[1]->path);
        self::assertSame(Ref::TYPE_INT, $reads[1]->type);
        self::assertSame(7, $reads[1]->default);
        self::assertTrue($reads[1]->missing);
        self::assertSame(__FILE__ . ':' . $line, $reads[1]->trace[0] ?? null);
    }

    public function testRequireThrowsWhenTheNodeIsAbsent(): void
    {
        $this->beforeProviders();

        $this->expectException(NotFoundException::class);
        Onlineconf::requireString('/app/gone');
    }

    public function testKillSwitchEmptiesTheImmediateModule(): void
    {
        $this->beforeProviders();
        putenv('ONLINECONF_CONFIG_OVERRIDE=false');
        ImmediateModule::flush();

        self::assertSame('dflt', Onlineconf::getString('/app/name', 'dflt'), 'get* fall back to their defaults');
        self::assertTrue(EagerReads::all()[0]->missing);

        $this->expectException(NotFoundException::class);
        Onlineconf::requireString('/app/name');
    }

    public function testTheModuleIsOpenedOncePerProcess(): void
    {
        $this->beforeProviders();

        self::assertSame(ImmediateModule::module(), ImmediateModule::module());
    }

    public function testTheBoundModuleWinsAsSoonAsTheProviderRegistered(): void
    {
        $this->useModule(['/app/name' => 'sfrom the container']);

        self::assertSame('from the container', Onlineconf::getString('/app/name', 'dflt'));
        self::assertSame([], EagerReads::all(), 'a normal read is not an eager read');
    }

    public function testGettersFallBackWhenTheModuleFileIsNotThere(): void
    {
        $this->beforeProviders(withModule: false);

        self::assertSame('dflt', Onlineconf::getString('/app/name', 'dflt'), 'a missing module must not stop the boot');
        self::assertSame(7, Onlineconf::getInt('/app/workers', 7));
        self::assertSame([], EagerReads::all(), 'nothing was read, so there is nothing to list');
        $error = EagerReads::openError();
        self::assertIsString($error);
        self::assertStringContainsString('TREE', $error);
    }

    public function testRequireThrowsWhenTheModuleFileIsNotThere(): void
    {
        $this->beforeProviders(withModule: false);

        $this->expectException(OpenException::class);
        Onlineconf::requireString('/app/name');
    }

    public function testOtherMethodsThrowWhenTheModuleFileIsNotThere(): void
    {
        $this->beforeProviders(withModule: false);

        $this->expectException(OpenException::class);
        Onlineconf::name();
    }

    public function testNamedArgumentsAreRecordedToo(): void
    {
        $this->beforeProviders();

        self::assertSame('From OnlineConf', Onlineconf::getString(path: '/app/name', default: 'dflt'));

        $reads = EagerReads::all();
        self::assertCount(1, $reads);
        self::assertSame('/app/name', $reads[0]->path);
        self::assertSame('dflt', $reads[0]->default);
    }

    public function testTheFacadeWorksWithoutAnApplication(): void
    {
        $this->beforeProviders();
        Facade::setFacadeApplication(null);

        self::assertSame('From OnlineConf', Onlineconf::getString('/app/name', 'dflt'), 'no container, no problem');
    }

    public function testInstallReleasesTheModuleOfTheConfigLoad(): void
    {
        $this->beforeProviders();
        $before = ImmediateModule::module();

        ConfigOverride::install($this->application());

        self::assertNotSame($before, ImmediateModule::module(), 'the handle of the config load is not kept open');
    }
}
