<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Onlineconf\Laravel\ConfigOverride;
use Onlineconf\Laravel\EagerReads;
use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Laravel\Ref;
use Symfony\Component\Console\Output\BufferedOutput;

final class MapCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        EagerReads::flush();
    }

    protected function tearDown(): void
    {
        EagerReads::flush();
        parent::tearDown();
    }

    /**
     * @param array<string, string|bool> $parameters
     *
     * @return array{int, string}
     */
    private function runCommand(array $parameters = []): array
    {
        $output = new BufferedOutput();
        $code = Artisan::call('onlineconf:map', $parameters, $output);

        return [$code, $output->fetch()];
    }

    private function install(): void
    {
        $this->useModule(['/app/name' => 'sFrom OnlineConf']);
        $this->config()->set('app.name', Onlineconf::refString('/app/name', 'From config'));
        $this->config()->set('app.secret', Onlineconf::refString('/app/secret'));
        $this->config()->set('services.queue.workers', 2);
        $this->config()->set('onlineconf.map', ['services.queue.workers' => '/app/workers']);
        ConfigOverride::install($this->application());
    }

    public function testTableListsTheDerivedMapAndTheEagerReads(): void
    {
        EagerReads::record('/app/eager', Ref::TYPE_INT, 5, true, 'TREE');
        $this->install();

        [$code, $output] = $this->runCommand();

        self::assertSame(0, $code);
        self::assertStringContainsString('app.name', $output);
        self::assertStringContainsString('/app/name', $output);
        self::assertStringContainsString('string', $output);
        self::assertStringContainsString('From config', $output, 'the fallback, not the OnlineConf value');
        self::assertStringContainsString('app.secret', $output);
        self::assertStringContainsString('yes', $output, 'a required node is marked');
        self::assertStringContainsString('services.queue.workers', $output);
        self::assertStringContainsString('/app/eager', $output);
        self::assertStringContainsString('Read while the configuration was loading', $output);
    }

    public function testJsonOutput(): void
    {
        EagerReads::record('/app/eager', Ref::TYPE_INT, 5, true, 'TREE');
        $this->install();

        [$code, $output] = $this->runCommand(['--json' => true]);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $code);
        self::assertIsArray($decoded);
        self::assertSame([
            'app.name' => ['path' => '/app/name', 'type' => 'string', 'required' => false, 'fallback' => 'From config'],
            'app.secret' => ['path' => '/app/secret', 'type' => 'string', 'required' => true, 'fallback' => null],
            'services.queue.workers' => ['path' => '/app/workers', 'type' => null, 'required' => false, 'fallback' => 2],
        ], $decoded['map']);
        $eager = $decoded['eager'];
        self::assertIsArray($eager);
        self::assertCount(1, $eager);
        $read = $eager[0];
        self::assertIsArray($read);
        self::assertSame('/app/eager', $read['path']);
        self::assertSame('int', $read['type']);
        self::assertSame(5, $read['default']);
        self::assertTrue($read['missing']);
        self::assertSame('TREE', $read['module']);
        $trace = $read['trace'];
        self::assertIsArray($trace);
        $frame = $trace[0] ?? '';
        self::assertIsString($frame);
        self::assertStringContainsString('MapCommandTest.php', $frame);
    }

    public function testNothingToShow(): void
    {
        [$code, $output] = $this->runCommand();

        self::assertSame(0, $code);
        self::assertStringContainsString('No OnlineConf nodes', $output);

        [$code, $output] = $this->runCommand(['--json' => true]);

        self::assertSame(0, $code);
        self::assertSame(['map' => [], 'eager' => []], json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }
}
