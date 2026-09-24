<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Onlineconf\Exception\OpenException;
use Onlineconf\Laravel\ModuleManager;
use Onlineconf\Settings;
use Psr\Log\NullLogger;

final class ModuleManagerTest extends TestCase
{
    private function manager(int $checkInterval = 0): ModuleManager
    {
        return new ModuleManager(new Settings($this->tempDir(), 'TREE'), new NullLogger(), $checkInterval);
    }

    public function testDefaultModuleIsResolvedFromSettings(): void
    {
        $this->writeModule(['/app/name' => 'sdemo']);

        self::assertSame('demo', $this->manager()->module()->getString('/app/name', ''));
    }

    public function testSameFileYieldsSameInstance(): void
    {
        $file = $this->writeModule(['/app/name' => 'sdemo']);
        $manager = $this->manager();

        $module = $manager->module();
        self::assertSame($module, $manager->module('TREE'));
        self::assertSame($module, $manager->module($file));
        self::assertSame($module, $manager->module($this->tempDir() . '/./TREE.cdb'));
    }

    public function testNamedModuleIsAnotherInstance(): void
    {
        $this->writeModule(['/app/name' => 'sdemo']);
        $this->writeModule(['/app/name' => 'sother'], 'other');
        $manager = $this->manager();

        self::assertNotSame($manager->module(), $manager->module('other'));
        self::assertSame('other', $manager->module('other')->getString('/app/name', ''));
    }

    public function testMissingFileThrowsOpenException(): void
    {
        $this->expectException(OpenException::class);

        $this->manager()->module('missing');
    }

    public function testCheckIntervalIsPassedToModules(): void
    {
        $this->writeModule(['/app/name' => 'sfirst']);
        $eager = $this->manager(0)->module();
        $lazy = $this->manager(3600)->module();
        self::assertSame('first', $eager->getString('/app/name', ''));
        self::assertSame('first', $lazy->getString('/app/name', ''));

        $this->writeModule(['/app/name' => 'ssecond']);

        self::assertSame('second', $eager->getString('/app/name', ''));
        self::assertSame('first', $lazy->getString('/app/name', ''));
    }

    public function testSettingsAreExposed(): void
    {
        $settings = new Settings($this->tempDir(), 'TREE');

        self::assertSame($settings, (new ModuleManager($settings, new NullLogger(), 0))->settings());
    }

    public function testFakeReplacesTheDefaultModule(): void
    {
        $this->writeModule(['/app/name' => 'sreal']);
        $manager = $this->manager();
        self::assertSame('real', $manager->module()->getString('/app/name', ''));

        $source = $manager->fake(['/app/name' => 'fake', '/app/opts' => ['pool' => 5]]);

        self::assertSame('fake', $manager->module()->getString('/app/name', ''));
        self::assertSame(['pool' => 5], $manager->module('TREE')->getArray('/app/opts', []));
        self::assertSame(['name', 'opts'], $manager->module()->children('/app'));
    }

    public function testFakeChangesAreVisibleImmediately(): void
    {
        $manager = $this->manager(3600);
        $source = $manager->fake(['/app/name' => 'one']);
        self::assertSame('one', $manager->module()->getString('/app/name', ''));

        $source->replaceValues(['/app/name' => 'two']);

        self::assertSame('two', $manager->module()->getString('/app/name', ''));
    }

    public function testNamedFakeDoesNotTouchTheDefaultModule(): void
    {
        $this->writeModule(['/app/name' => 'sreal']);
        $manager = $this->manager();

        $manager->fake(['/app/name' => 'fake'], 'other');

        self::assertSame('fake', $manager->module('other')->getString('/app/name', ''));
        self::assertSame('real', $manager->module()->getString('/app/name', ''));
    }

    public function testFakeWorksWithoutAnyFile(): void
    {
        $manager = $this->manager();

        $manager->fake(['/app/name' => 'fake']);

        self::assertSame('fake', $manager->module()->getString('/app/name', ''));
    }

    public function testAFailedOpenIsRememberedAndNotedOnce(): void
    {
        $log = new TestHandler();
        $manager = new ModuleManager(new Settings($this->tempDir(), 'TREE'), new Logger('test', [$log]), 0);

        try {
            $manager->module();
            self::fail('there is no module file yet');
        } catch (OpenException $first) {
        }
        $this->writeModule(['/app/name' => 'sdemo']);

        try {
            $manager->module();
            self::fail('a process that started without the file keeps serving without it');
        } catch (OpenException $second) {
            self::assertSame($first, $second, 'the remembered failure, not a new attempt');
        }
        self::assertCount(1, $log->getRecords());
        self::assertTrue($log->hasDebugThatContains('TREE.cdb'), 'no module is a normal state, not an error');
    }

    public function testAFakeReplacesARememberedFailure(): void
    {
        $manager = $this->manager();
        try {
            $manager->module();
        } catch (OpenException) {
        }

        $manager->fake(['/app/name' => 'fake']);

        self::assertSame('fake', $manager->module()->getString('/app/name', ''));
    }

    public function testAMissingFileRaisesNoPhpWarning(): void
    {
        $errors = [];
        set_error_handler(static function (int $level, string $message) use (&$errors): bool {
            $errors[] = $message; // @-suppressed ones too: Collision reports those in the application's tests

            return true;
        });
        try {
            $this->manager()->module();
            self::fail('there is no module file');
        } catch (OpenException $e) {
            self::assertStringContainsString('TREE.cdb: no such file', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $errors);
    }
}
