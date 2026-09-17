<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Laravel\Testing\InteractsWithOnlineconf;
use Onlineconf\Module;

final class InteractsWithOnlineconfTest extends TestCase
{
    use InteractsWithOnlineconf;

    public function testFakeOnlineconfReplacesTheDefaultModule(): void
    {
        $source = $this->fakeOnlineconf(['/app/name' => 'fake']);

        $module = $this->application()->make(Module::class);
        assert($module instanceof Module);
        self::assertSame('fake', $module->getString('/app/name', ''));
        self::assertSame('fake', Onlineconf::getString('/app/name', ''));

        $source->replaceValues(['/app/name' => 'changed']);

        self::assertSame('changed', Onlineconf::getString('/app/name', ''));
    }

    public function testFakeOnlineconfForANamedModule(): void
    {
        $this->fakeOnlineconf(['/app/name' => 'fake'], 'other');

        self::assertSame('fake', Onlineconf::module('other')->getString('/app/name', ''));
    }
}
