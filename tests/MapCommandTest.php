<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use Onlineconf\Laravel\ConfigOverride;
use Onlineconf\Laravel\EagerReads;
use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Laravel\Ref;
use Onlineconf\Laravel\Tests\Support\Csv;
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
        $this->config()->set('app.name', Onlineconf::getRefString('/app/name', 'From config'));
        $this->config()->set('app.secret', Onlineconf::requireRefString('/app/secret'));
        $this->config()->set('services.queue.workers', Onlineconf::getRefInt('/app/workers', 2));
        ConfigOverride::install($this->application());
    }

    public function testTableListsTheDerivedMapAndTheEagerReads(): void
    {
        EagerReads::record('/app/eager', Ref::TYPE_INT, 5);
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
        EagerReads::record('/app/eager', Ref::TYPE_INT, 5);
        $this->install();

        [$code, $output] = $this->runCommand(['--json' => true]);
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $code);
        self::assertIsArray($decoded);
        self::assertSame([
            'app.name' => ['path' => '/app/name', 'type' => 'string', 'required' => false, 'fallback' => 'From config', 'transform' => false],
            'app.secret' => ['path' => '/app/secret', 'type' => 'string', 'required' => true, 'fallback' => null, 'transform' => false],
            'services.queue.workers' => ['path' => '/app/workers', 'type' => 'int', 'required' => false, 'fallback' => 2, 'transform' => false],
        ], $decoded['map']);
        $eager = $decoded['eager'];
        self::assertIsArray($eager);
        self::assertCount(1, $eager);
        $read = $eager[0];
        self::assertIsArray($read);
        self::assertSame(['path' => '/app/eager', 'type' => 'int', 'default' => 5], $read);
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

    public function testATransformedMarkerShowsItsRawFallback(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());
        $this->config()->set('app.hosts', Onlineconf::getRefString('/app/hosts', 'a, b', [Csv::class, 'split']));
        ConfigOverride::install($this->application());

        [$code, $table] = $this->runCommand();
        [, $json] = $this->runCommand(['--json' => true]);

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/Transform/', $table);
        self::assertMatchesRegularExpression('/app\.hosts\s*\|\s*\/app\/hosts\s*\|\s*string\s*\|\s*no\s*\|\s*yes\s*\|\s*a, b\s*\|/', $table);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(
            ['app.hosts' => ['path' => '/app/hosts', 'type' => 'string', 'required' => false, 'fallback' => 'a, b', 'transform' => true]],
            $decoded['map'],
            'the fallback as written, not as shaped',
        );
    }
}
