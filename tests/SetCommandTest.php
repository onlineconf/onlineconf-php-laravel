<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Onlineconf\Cdb\CdbReader;
use Onlineconf\Exception\OpenException;
use Onlineconf\Laravel\ModuleManager;
use Symfony\Component\Console\Output\BufferedOutput;

final class SetCommandTest extends TestCase
{
    /** @var array<string, string> */
    private const MODULE = [
        '/' => 'j["app"]',
        '/app/' => 'j["name","opts"]',
        '/app/name' => 'sdemo',
        '/app/opts' => 'j{"pool": 5}',
    ];

    /**
     * @param array<string, string|bool> $parameters
     *
     * @return array{int, string}
     */
    private function runCommand(array $parameters): array
    {
        $output = new BufferedOutput();
        $code = Artisan::call('onlineconf:set', $parameters, $output);

        return [$code, rtrim($output->fetch(), "\n")];
    }

    public function testStringValueIsWrittenAndChildListsFollow(): void
    {
        $file = $this->useModule(self::MODULE);

        [$code, $out] = $this->runCommand(['path' => '/app/db/host', 'value' => 'db.local']);

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('set /app/db/host', $out);
        $raw = CdbReader::read($file);
        self::assertSame('sdb.local', $raw['/app/db/host']);
        self::assertSame('sdemo', $raw['/app/name'], 'other keys survive');
        self::assertSame('j["db","name","opts"]', $raw['/app/'], 'the parent list gains the new child, sorted');
        self::assertSame('j["host"]', $raw['/app/db/']);
        self::assertSame('j["app"]', $raw['/']);

        $conf = (string) file_get_contents($this->tempDir() . '/TREE.conf');
        self::assertStringContainsString("\n#! Name TREE\n", $conf);
        self::assertStringContainsString("\n/app/db/host db.local\n", $conf);
        self::assertStringContainsString("\n/app/opts:JSON {\"pool\": 5}\n", $conf);
        self::assertStringEndsWith("\n#EOF", $conf);
    }

    public function testExistingValueIsReplaced(): void
    {
        $file = $this->useModule(self::MODULE);

        [$code] = $this->runCommand(['path' => '/app/name', 'value' => 'renamed']);

        self::assertSame(0, $code);
        self::assertSame('srenamed', CdbReader::read($file)['/app/name']);
    }

    public function testJsonOptionStoresATypedJsonValue(): void
    {
        $file = $this->useModule(self::MODULE);

        [$code] = $this->runCommand(['path' => '/app/opts', 'value' => '{"pool":9}', '--json' => true]);

        self::assertSame(0, $code);
        self::assertSame('j{"pool":9}', CdbReader::read($file)['/app/opts']);
    }

    public function testInvalidJsonIsRejected(): void
    {
        $file = $this->useModule(self::MODULE);

        [$code, $out] = $this->runCommand(['path' => '/app/opts', 'value' => '{not json', '--json' => true]);

        self::assertSame(2, $code);
        self::assertStringContainsString('Invalid JSON', $out);
        self::assertSame(self::MODULE['/app/opts'], CdbReader::read($file)['/app/opts'], 'the file is untouched');
    }

    public function testDeleteRemovesTheKeyAndItsListEntry(): void
    {
        $file = $this->useModule(self::MODULE);

        [$code, $out] = $this->runCommand(['path' => '/app/opts', '--delete' => true]);

        self::assertSame(0, $code, $out);
        $raw = CdbReader::read($file);
        self::assertArrayNotHasKey('/app/opts', $raw);
        self::assertSame('j["name"]', $raw['/app/']);
    }

    public function testDeleteOfAMissingKeyIsNotFound(): void
    {
        $this->useModule(self::MODULE);

        self::assertSame([1, 'No such key: /app/none'], $this->runCommand(['path' => '/app/none', '--delete' => true]));
    }

    public function testValueIsRequiredUnlessDeleting(): void
    {
        $this->useModule(self::MODULE);

        [$code, $out] = $this->runCommand(['path' => '/app/name']);

        self::assertSame(2, $code);
        self::assertStringContainsString('value is required', $out);
    }

    public function testChildListPathIsRejected(): void
    {
        $file = $this->useModule(self::MODULE);

        [$code, $out] = $this->runCommand(['path' => '/app/', 'value' => 'x']);

        self::assertSame(2, $code);
        self::assertStringContainsString('generated', $out);
        self::assertSame(self::MODULE['/app/name'], CdbReader::read($file)['/app/name'], 'the file is untouched');
    }

    public function testPathWithoutLeadingSlashIsRejected(): void
    {
        $file = $this->useModule(self::MODULE);

        [$code, $out] = $this->runCommand(['path' => 'app/name', 'value' => 'x']);

        self::assertSame(2, $code);
        self::assertStringContainsString('must start with "/"', $out);
        self::assertSame(self::MODULE['/app/name'], CdbReader::read($file)['/app/name'], 'the file is untouched');
    }

    public function testMissingModuleFileIsAnError(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());

        [$code, $out] = $this->runCommand(['path' => '/a', 'value' => 'b']);

        self::assertSame(2, $code);
        self::assertStringContainsString('TREE.cdb', $out);
        self::assertFileDoesNotExist($this->tempDir() . '/TREE.cdb', 'the command edits a module, it does not create one');
    }

    public function testTheRunningProcessSeesTheWrittenModule(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());
        $manager = $this->application()->make(ModuleManager::class);
        assert($manager instanceof ModuleManager);
        try {
            $manager->module();
            self::fail('there is no module file yet');
        } catch (OpenException) {
        }
        $this->writeModule(self::MODULE);

        [$code] = $this->runCommand(['path' => '/app/name', 'value' => 'written']);

        self::assertSame(0, $code);
        self::assertSame('written', $manager->module()->getString('/app/name', ''), 'the remembered failed open is gone');
    }

    public function testCorruptModuleFileIsAnError(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());
        file_put_contents($this->tempDir() . '/TREE.cdb', "this is not a cdb file\n");

        [$code, $out] = $this->runCommand(['path' => '/a', 'value' => 'b']);

        self::assertSame(2, $code);
        self::assertStringContainsString('not a valid CDB file', $out);
    }

    public function testModuleOptionSelectsAnotherFile(): void
    {
        $this->useModule(self::MODULE);
        $other = $this->writeModule(['/' => 'j["k"]', '/k' => 'sv'], 'other');

        [$code] = $this->runCommand(['path' => '/k', 'value' => 'w', '--module' => 'other']);

        self::assertSame(0, $code);
        self::assertSame('sw', CdbReader::read($other)['/k']);
        self::assertSame('sdemo', CdbReader::read($this->tempDir() . '/TREE.cdb')['/app/name'], 'the default module is untouched');
    }

    public function testReadOnlyDirectoryIsAnError(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can write anywhere');
        }
        $this->useModule(self::MODULE);
        chmod($this->tempDir(), 0o500);
        try {
            [$code, $out] = $this->runCommand(['path' => '/app/name', 'value' => 'x']);
        } finally {
            chmod($this->tempDir(), 0o700);
        }

        self::assertSame(2, $code);
        self::assertStringContainsString('not writable', $out);
    }

    public function testUnwritableConfFileIsAnError(): void
    {
        $file = $this->useModule(self::MODULE);
        mkdir($this->tempDir() . '/TREE.conf');

        [$code, $out] = $this->runCommand(['path' => '/app/name', 'value' => 'renamed']);

        self::assertSame(2, $code);
        self::assertStringContainsString('cannot write', $out);
        self::assertSame('srenamed', CdbReader::read($file)['/app/name'], 'the cdb write already went through');
    }
}
