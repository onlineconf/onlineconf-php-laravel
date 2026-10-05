<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Support\Facades\Facade;
use Onlineconf\Exception\InvalidDefaultException;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\OpenException;
use Onlineconf\Laravel\EagerReads;
use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Laravel\ImmediateModule;
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

    public function testWithoutARequiredModuleFileGettersThrowToo(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());

        $this->expectException(OpenException::class);
        $this->expectExceptionMessage('set ONLINECONF_REQUIRED=false to start without it');
        Onlineconf::getString('/x', 'd');
    }

    public function testGettersReturnTheirDefaultsWithoutAnOptionalModuleFile(): void
    {
        $this->optionalModule();
        $this->config()->set('onlineconf.dir', $this->tempDir());

        self::assertSame('d', Onlineconf::getString('/x', 'd'));
        self::assertSame(3306, Onlineconf::getInt('/x', '3306'), 'a string default is read as an int');
        self::assertNull(Onlineconf::getInt('/x', ''), 'an empty variable is not set');
        self::assertSame(['a'], Onlineconf::getStrings('/x', ['a']));
        self::assertSame(['k' => 1], Onlineconf::getArray('/x', ['k' => 1]));
        self::assertSame('raw', Onlineconf::get('/x', 'raw'));
        self::assertSame(3, Onlineconf::getInt('/x', 3));
        self::assertSame('named', Onlineconf::getString(default: 'named', path: '/x'), 'named arguments, in any order');
    }

    public function testRequireNamesTheMissingOptionalModuleFile(): void
    {
        $this->optionalModule();
        $this->config()->set('onlineconf.dir', $this->tempDir());

        self::assertSame('missing', Onlineconf::version());

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('is missing');
        Onlineconf::requireString('/x');
    }

    public function testABadStringDefaultFailsEvenWhereTheNodeExists(): void
    {
        $this->useModule(['/port' => 's8080']);

        $this->expectException(InvalidDefaultException::class);
        $this->expectExceptionMessage('/port: invalid default for int: "abc" is not an integer');
        Onlineconf::getInt('/port', 'abc');
    }

    public function testAnExplicitNullDefaultByNameIsTheDefaultWithoutAModule(): void
    {
        $this->optionalModule();
        $this->config()->set('onlineconf.dir', $this->tempDir());

        self::assertNull(Onlineconf::get(default: null, path: '/p'), 'not the path');
        self::assertNull(Onlineconf::get(path: '/p', default: null));
        self::assertSame('d', Onlineconf::getString('/p', default: 'd'), 'a positional path with a named default');
    }

    public function testAnExplicitNullDefaultByNameIsTheDefaultBeforeBootToo(): void
    {
        $this->optionalModule();
        EagerReads::flush();
        ImmediateModule::flush();
        putenv('ONLINECONF_DIR=' . $this->tempDir());
        Facade::setFacadeApplication(null);
        try {
            self::assertNull(Onlineconf::get(default: null, path: '/p'));
            self::assertSame('d', Onlineconf::getString('/q', default: 'd'));

            $reads = EagerReads::all();
            self::assertSame(['/p', null], [$reads[0]->path, $reads[0]->default]);
            self::assertSame(['/q', 'd'], [$reads[1]->path, $reads[1]->default]);
        } finally {
            putenv('ONLINECONF_DIR');
            Facade::setFacadeApplication($this->application());
            EagerReads::flush();
            ImmediateModule::flush();
        }
    }

    public function testANullDefaultWithAModuleAndNoNodeIsNull(): void
    {
        // DA: Onlineconf::getString('/da/...', env('X')) with X unset, on a pod that has its module.
        $this->useModule(['/other' => 'sx']);

        self::assertNull(Onlineconf::getString('/p', null));
        self::assertNull(Onlineconf::getInt('/p', null));
        self::assertNull(Onlineconf::getStrings('/p', null));
        self::assertSame('x', Onlineconf::getString('/other', null), 'a present node is read as always');
    }
}
