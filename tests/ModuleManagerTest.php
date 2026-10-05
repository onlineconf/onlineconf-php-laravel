<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Onlineconf\Cdb\CdbWriter;
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

    public function testAMissingRequiredFileSaysHowToStartWithoutIt(): void
    {
        $this->expectException(OpenException::class);
        $this->expectExceptionMessage('TREE.cdb: no such file; set ONLINECONF_REQUIRED=false to start without it');

        $this->manager()->module();
    }

    public function testAMissingOptionalFileIsAnEmptyModuleThatPicksTheFileUp(): void
    {
        // The module directory is reached through a symlink, as a Kubernetes configMap (..data/) or macOS
        // (/var → /private/var) does: once the file exists its real path differs from the configured one.
        $real = $this->tempDir() . '/real';
        mkdir($real, 0o700);
        $link = sys_get_temp_dir() . '/onlineconf-laravel-link-' . bin2hex(random_bytes(6));
        symlink($real, $link);
        $manager = new ModuleManager(new Settings($link, 'TREE', false), new NullLogger(), 0);

        try {
            $module = $manager->module();
            self::assertSame('missing', $module->version());
            self::assertSame(80, $module->getInt('/port', '80'));
            CdbWriter::write($real . '/TREE.cdb', ['/port' => 's8080']);

            self::assertSame(8080, $module->getInt('/port', '80'), 'the module a process holds opens the file');
            self::assertSame($module, $manager->module(), 'and stays the module of that name, symlink or not');
        } finally {
            unlink($link);
        }
    }

    public function testARequiredFileThatAppearsIsOpenedByTheNextCall(): void
    {
        $manager = $this->manager();
        try {
            $manager->module();
            self::fail('there is no module file yet');
        } catch (OpenException) {
        }
        $this->writeModule(['/app/name' => 'sdemo']);

        self::assertSame('demo', $manager->module()->getString('/app/name'), 'a failed open is not remembered');
    }

    public function testAFakeNeedsNoFileInEitherMode(): void
    {
        foreach ([true, false] as $required) {
            $manager = new ModuleManager(new Settings($this->tempDir(), 'TREE', $required), new NullLogger(), 0);

            $manager->fake(['/app/name' => 'fake']);

            self::assertSame('fake', $manager->module()->getString('/app/name', ''));
        }
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

    public function testAConfigMapSwapIsServed(): void
    {
        // kubelet updates a configMap: the new data goes to a new hidden directory, "..data" is retargeted to it
        // atomically, the old directory is deleted. The module file is "<mount>/TREE.cdb" -> "..data/TREE.cdb".
        $mount = $this->tempDir() . '/mount';
        mkdir($mount . '/..2026_one', 0o700, true);
        CdbWriter::write($mount . '/..2026_one/TREE.cdb', ['/k' => 'sone']);
        symlink('..2026_one', $mount . '/..data');
        symlink('..data/TREE.cdb', $mount . '/TREE.cdb');
        $manager = new ModuleManager(new Settings($mount, 'TREE'), new NullLogger(), 0);
        self::assertSame('one', $manager->module()->getString('/k'));

        mkdir($mount . '/..2026_two', 0o700);
        CdbWriter::write($mount . '/..2026_two/TREE.cdb', ['/k' => 'stwo']);
        symlink('..2026_two', $mount . '/..data_tmp');
        rename($mount . '/..data_tmp', $mount . '/..data');
        unlink($mount . '/..2026_one/TREE.cdb');
        rmdir($mount . '/..2026_one');

        self::assertSame('two', $manager->module()->getString('/k'), 'the module follows the symlink, not the old real path');
    }
}
