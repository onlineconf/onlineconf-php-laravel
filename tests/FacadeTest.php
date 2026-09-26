<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Onlineconf\Exception\OpenException;
use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Module;

final class FacadeTest extends TestCase
{
    public function testGettersProxyToTheDefaultModule(): void
    {
        $this->useModule([
            '/app/name' => 'sdemo',
            '/app/port' => 's8080',
            '/app/timeout' => 's1.5s',
            '/app/opts' => 'j{"pool":5}',
        ]);

        self::assertSame('demo', Onlineconf::getString('/app/name', ''));
        self::assertSame(8080, Onlineconf::getInt('/app/port', 0));
        self::assertSame(1500, Onlineconf::getDurationMs('/app/timeout', 0));
        self::assertSame(['pool' => 5], Onlineconf::getArray('/app/opts', []));
        self::assertSame(8080, Onlineconf::subtree('/app')->requireInt('/port'));
        self::assertTrue(Onlineconf::has('/app/name'));
        self::assertFalse(Onlineconf::has('/app/missing'));
    }

    public function testFacadeRootIsTheBoundModule(): void
    {
        $this->useModule(['/app/name' => 'sdemo']);

        self::assertSame($this->application()->make(Module::class), Onlineconf::getFacadeRoot());
    }

    public function testNamedModule(): void
    {
        $this->useModule(['/app/name' => 'stree']);
        $this->writeModule(['/app/name' => 'sother'], 'other');

        self::assertSame('other', Onlineconf::module('other')->getString('/app/name', ''));
        self::assertSame('tree', Onlineconf::module()->getString('/app/name', ''));
    }

    public function testFakeReplacesTheDefaultModuleEverywhere(): void
    {
        $this->useModule(['/app/name' => 'sreal']);
        self::assertSame('real', Onlineconf::getString('/app/name', ''));

        $source = Onlineconf::fake(['/app/name' => 'fake', '/app/opts' => ['pool' => 5]]);

        self::assertSame('fake', Onlineconf::getString('/app/name', ''));
        $module = $this->application()->make(Module::class);
        assert($module instanceof Module);
        self::assertSame('fake', $module->getString('/app/name', ''));
        self::assertSame(['pool' => 5], $module->getArray('/app/opts', []));

        $source->replaceValues(['/app/name' => 'changed']);

        self::assertSame('changed', Onlineconf::getString('/app/name', ''));
    }

    public function testFakeNeedsNoFiles(): void
    {
        Onlineconf::fake(['/app/name' => 'fake']);

        self::assertSame('fake', Onlineconf::getString('/app/name', ''));
    }

    public function testNamedFake(): void
    {
        $this->useModule(['/app/name' => 'sreal']);

        Onlineconf::fake(['/app/name' => 'fake'], 'other');

        self::assertSame('fake', Onlineconf::module('other')->getString('/app/name', ''));
        self::assertSame('real', Onlineconf::getString('/app/name', ''));
    }

    public function testGettersReturnTheirDefaultsWhenThereIsNoModule(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());

        self::assertSame('d', Onlineconf::getString('/x', 'd'));
        self::assertSame(['a'], Onlineconf::getStrings('/x', ['a']));
        self::assertSame(['k' => 1], Onlineconf::getArray('/x', ['k' => 1]));
        self::assertSame('raw', Onlineconf::get('/x', 'raw'));
        self::assertSame(3, Onlineconf::getInt('/x', 3));
        self::assertSame('named', Onlineconf::getString(default: 'named', path: '/x'), 'named arguments, in any order');
    }

    public function testRequireThrowsWhenThereIsNoModule(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());

        $this->expectException(OpenException::class);
        Onlineconf::requireString('/x');
    }

    public function testMethodsThatAreNotReadsThrowWhenThereIsNoModule(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());

        $this->expectException(OpenException::class);
        Onlineconf::version();
    }
}
