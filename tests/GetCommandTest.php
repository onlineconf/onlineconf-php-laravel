<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

final class GetCommandTest extends TestCase
{
    /** @var array<string, string> */
    private const MODULE = [
        '/' => 'j["app"]',
        '/app/' => 'j["name","opts","broken","weird"]',
        '/app/name' => 'sdemo',
        '/app/opts' => 'j{"pool": 5}',
        '/app/broken' => 'j{not json',
        '/app/weird' => 'c\x01',
    ];

    /**
     * @param array<string, string|bool> $parameters
     *
     * @return array{int, string}
     */
    private function runCommand(array $parameters): array
    {
        $output = new BufferedOutput();
        $code = Artisan::call('onlineconf:get', $parameters, $output);

        return [$code, rtrim($output->fetch(), "\n")];
    }

    public function testStringValueIsPrintedAsIs(): void
    {
        $this->useModule(self::MODULE);

        self::assertSame([0, 'demo'], $this->runCommand(['path' => '/app/name']));
    }

    public function testJsonValueIsPrintedAsStored(): void
    {
        $this->useModule(self::MODULE);

        self::assertSame([0, '{"pool": 5}'], $this->runCommand(['path' => '/app/opts']));
    }

    public function testJsonOptionEncodesAnyValue(): void
    {
        $this->useModule(self::MODULE);

        self::assertSame([0, '"demo"'], $this->runCommand(['path' => '/app/name', '--json' => true]));
        self::assertSame([0, '{"pool":5}'], $this->runCommand(['path' => '/app/opts', '--json' => true]));
    }

    public function testTreeOptionPrintsPrettyJson(): void
    {
        $this->useModule([
            '/' => 'j["app"]',
            '/app/' => 'j["name","opts"]',
            '/app/name' => 'sdemo',
            '/app/opts' => 'j{"pool": 5}',
        ]);

        [$code, $output] = $this->runCommand(['path' => '/app', '--tree' => true]);

        self::assertSame(0, $code);
        self::assertSame(['name' => 'demo', 'opts' => ['pool' => 5]], json_decode($output, true));
        self::assertStringContainsString("\n", $output, 'pretty printed');
    }

    public function testMissingKeyExitsWithOne(): void
    {
        $this->useModule(self::MODULE);

        self::assertSame([1, 'No such key: /app/missing'], $this->runCommand(['path' => '/app/missing']));
        self::assertSame([1, 'No such key: /app/missing'], $this->runCommand(['path' => '/app/missing', '--json' => true]));
    }

    public function testUnknownTypeByteExitsWithTwo(): void
    {
        $this->useModule(self::MODULE);

        [$code, $output] = $this->runCommand(['path' => '/app/weird']);

        self::assertSame(2, $code);
        self::assertStringContainsString('/app/weird', $output);
    }

    public function testClientErrorsExitWithTwo(): void
    {
        $this->useModule(self::MODULE);

        [$code, $output] = $this->runCommand(['path' => '/app/broken', '--json' => true]);

        self::assertSame(2, $code);
        self::assertStringContainsString('/app/broken', $output);
    }

    public function testNonUtf8ValueExitsWithTwoInJsonMode(): void
    {
        $this->useModule(['/app/bytes' => "s\xC0\xFF"]);

        self::assertSame([0, "\xC0\xFF"], $this->runCommand(['path' => '/app/bytes']));

        [$code, $output] = $this->runCommand(['path' => '/app/bytes', '--json' => true]);

        self::assertSame(2, $code);
        self::assertStringContainsStringIgnoringCase('utf-8', $output);
    }

    public function testMissingModuleFileExitsWithTwo(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());

        [$code, $output] = $this->runCommand(['path' => '/app/name']);

        self::assertSame(2, $code);
        self::assertStringContainsString('TREE.cdb', $output);
    }

    public function testModuleOption(): void
    {
        $this->useModule(self::MODULE);
        $this->writeModule(['/app/name' => 'sother'], 'other');

        self::assertSame([0, 'other'], $this->runCommand(['path' => '/app/name', '--module' => 'other']));
    }
}
