<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Laravel\Config\OverridingRepository;
use Onlineconf\Laravel\ConfigOverride;
use Onlineconf\Laravel\EagerReads;
use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Laravel\MissingValue;
use Onlineconf\Laravel\ModuleManager;
use Onlineconf\Laravel\Ref;
use Onlineconf\Laravel\Tests\Support\RecordingHandler;

final class ConfigOverrideTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingHandler::$missing = [];
        EagerReads::flush();
    }

    public function testInstallReplacesTheRepositoryAndReadsMappedKeys(): void
    {
        $this->useModule(['/app/name' => 'sFrom OnlineConf']);
        $this->config()->set('app.name', 'From config');
        $this->config()->set('onlineconf.map', ['app.name' => '/app/name', 'app.missing' => '/app/missing']);

        ConfigOverride::install($this->application());

        $config = $this->application()->make(Repository::class);
        self::assertInstanceOf(OverridingRepository::class, $config);
        self::assertSame($config, $this->application()->make('config'));
        self::assertSame('From OnlineConf', $config->get('app.name'));
        self::assertSame('From OnlineConf', config('app.name'));
        self::assertNull(config('app.missing'));
        self::assertSame(
            [
                'app.name' => ['path' => '/app/name', 'type' => null, 'required' => false],
                'app.missing' => ['path' => '/app/missing', 'type' => null, 'required' => false],
            ],
            config('onlineconf.map'),
            'the 1.1 map format is normalised in place',
        );
    }

    public function testManagerIsSharedWithTheProviderSoFakesReachConfig(): void
    {
        $this->useModule(['/app/name' => 'sreal']);
        $this->config()->set('onlineconf.map', ['app.name' => '/app/name']);

        ConfigOverride::install($this->application());

        self::assertSame('real', config('app.name'));
        self::assertSame($this->application()->make(ModuleManager::class), $this->application()->make(ModuleManager::class));

        Onlineconf::fake(['/app/name' => 'fake']);

        self::assertSame('fake', config('app.name'));
        self::assertSame('fake', Onlineconf::getString('/app/name', ''));
    }

    public function testKillSwitchLeavesTheRepositoryAlone(): void
    {
        $this->config()->set('onlineconf.map', ['app.name' => '/app/name']);
        $this->config()->set('onlineconf.config_override', false);
        $before = $this->config();

        ConfigOverride::install($this->application());

        self::assertSame($before, $this->config());
    }

    public function testEmptyMapLeavesTheRepositoryAlone(): void
    {
        $before = $this->config();

        ConfigOverride::install($this->application());

        self::assertSame($before, $this->config());
    }

    public function testInvalidMapEntriesAreIgnored(): void
    {
        $this->useModule(['/app/name' => 'sFrom OnlineConf', '/junk' => 'sjunk']);
        $this->config()->set('onlineconf.map', ['app.name' => '/app/name', 0 => '/junk', 'app.other' => 5, '' => '/junk']);

        ConfigOverride::install($this->application());

        self::assertSame('From OnlineConf', config('app.name'));
        self::assertFalse($this->config()->has('app.other'));
        self::assertFalse($this->config()->has('0'));
    }

    public function testRegisterHooksIntoTheBootstrapSequence(): void
    {
        $base = $this->tempDir() . '/app';
        mkdir($base . '/config', 0o700, true);
        file_put_contents(
            $base . '/config/app.php',
            "<?php return ['name' => 'From config', 'env' => 'testing', 'timezone' => 'UTC', 'locale' => 'en', 'fallback_locale' => 'en', 'providers' => [], 'aliases' => []];",
        );
        file_put_contents(
            $base . '/config/logging.php',
            "<?php return ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \\Monolog\\Handler\\NullHandler::class]]];",
        );
        $this->writeModule(['/app/name' => 'sFrom OnlineConf']);
        file_put_contents(
            $base . '/config/onlineconf.php',
            sprintf(
                "<?php return ['dir' => %s, 'module' => null, 'check_interval' => 0, 'log_channel' => null, 'config_override' => true, 'map' => ['app.name' => '/app/name']];",
                var_export($this->tempDir(), true),
            ),
        );

        $app = new Application($base);
        try {
            ConfigOverride::register($app);
            $app->bootstrapWith([LoadConfiguration::class]);

            $config = $app->make(Repository::class);
            self::assertInstanceOf(OverridingRepository::class, $config);
            self::assertSame('From OnlineConf', $config->get('app.name'));
            self::assertSame('testing', $config->get('app.env'));
        } finally {
            $app->flush();
            Container::setInstance($this->application());
        }
    }

    public function testOnMissingClassIsResolvedFromTheContainer(): void
    {
        $this->useModule(['/app/name' => 'sFrom OnlineConf']);
        $this->config()->set('app.missing', 'dflt');
        $this->config()->set('onlineconf.map', ['app.name' => '/app/name', 'app.missing' => '/app/missing']);
        $this->config()->set('onlineconf.on_missing', RecordingHandler::class);

        ConfigOverride::install($this->application());

        self::assertSame('From OnlineConf', config('app.name'));
        self::assertSame('dflt', config('app.missing'));
        self::assertCount(1, RecordingHandler::$missing);
        $missing = RecordingHandler::$missing[0];
        self::assertSame('app.missing', $missing->configKey);
        self::assertSame('/app/missing', $missing->path);
        self::assertSame('dflt', $missing->fallback);
        self::assertSame('TREE', $missing->module);
        self::assertSame(__FILE__, $missing->file, 'the config() call above is the call site');
    }

    public function testOnMissingClosureIsCalledAsIs(): void
    {
        $seen = [];
        $this->useModule([]);
        $this->config()->set('onlineconf.map', ['app.missing' => '/app/missing']);
        $this->config()->set('onlineconf.on_missing', static function (MissingValue $missing) use (&$seen): void {
            $seen[] = $missing->path;
        });

        ConfigOverride::install($this->application());
        config('app.missing');

        self::assertSame(['/app/missing'], $seen);
    }

    public function testNonInvokableOnMissingClassFails(): void
    {
        $this->useModule([]);
        $this->config()->set('onlineconf.map', ['app.missing' => '/app/missing']);
        $this->config()->set('onlineconf.on_missing', \stdClass::class);
        ConfigOverride::install($this->application());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('onlineconf.on_missing: stdClass is not invokable');
        config('app.missing');
    }

    public function testUnknownOnMissingClassFailsAtInstall(): void
    {
        $this->config()->set('onlineconf.map', ['app.missing' => '/app/missing']);
        $this->config()->set('onlineconf.on_missing', 'Nope\\Missing');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('onlineconf.on_missing: class Nope\\Missing does not exist');
        ConfigOverride::install($this->application());
    }

    public function testInvalidOnMissingValueFailsAtInstall(): void
    {
        $this->config()->set('onlineconf.map', ['app.missing' => '/app/missing']);
        $this->config()->set('onlineconf.on_missing', 42);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('onlineconf.on_missing must be null, a class name or a Closure');
        ConfigOverride::install($this->application());
    }

    public function testMarkersBecomeMapEntriesAndLeaveTheirFallbacksBehind(): void
    {
        $this->useModule([
            '/app/name' => 'sFrom OnlineConf',
            '/app/workers' => 's8',
            '/app/ttl' => 's1m',
        ]);
        $this->config()->set('app.name', Onlineconf::refString('/app/name', 'From config'));
        $this->config()->set('services.queue', [
            'workers' => Onlineconf::refInt('/app/workers', 2),
            'ttl' => Onlineconf::refDuration('/app/ttl', 5.0),
            'driver' => 'sync',
        ]);

        ConfigOverride::install($this->application());

        self::assertSame('From OnlineConf', config('app.name'));
        self::assertSame(8, config('services.queue.workers'));
        self::assertSame(60.0, config('services.queue.ttl'));
        self::assertSame('sync', config('services.queue.driver'));
        self::assertSame(
            [
                'app.name' => ['path' => '/app/name', 'type' => Ref::TYPE_STRING, 'required' => false],
                'services.queue.workers' => ['path' => '/app/workers', 'type' => Ref::TYPE_INT, 'required' => false],
                'services.queue.ttl' => ['path' => '/app/ttl', 'type' => Ref::TYPE_DURATION, 'required' => false],
            ],
            config('onlineconf.map'),
            'the map is derived from the markers',
        );

        $config = $this->config();
        self::assertInstanceOf(OverridingRepository::class, $config);
        $all = $config->all();
        self::assertIsArray($all['app']);
        self::assertSame('From config', $all['app']['name'], 'config:cache sees the fallback, never a marker');
    }

    public function testMarkersAreReplacedEvenWhenTheOverrideIsOff(): void
    {
        $this->useModule(['/app/name' => 'sFrom OnlineConf']);
        $this->config()->set('app.name', Onlineconf::refString('/app/name', 'From config'));
        $this->config()->set('onlineconf.config_override', false);
        $before = $this->config();

        ConfigOverride::install($this->application());

        self::assertSame($before, $this->config(), 'the repository is left alone');
        self::assertSame('From config', config('app.name'), 'but the marker is gone');
        self::assertSame(
            ['app.name' => ['path' => '/app/name', 'type' => Ref::TYPE_STRING, 'required' => false]],
            config('onlineconf.map'),
            'the derived map is available to tooling even with the override off',
        );
    }

    public function testMarkerWithoutAFallbackRequiresTheNode(): void
    {
        $this->useModule(['/app/secret' => 'svalue']);
        $this->config()->set('app.secret', Onlineconf::refString('/app/secret'));
        $this->config()->set('app.gone', Onlineconf::refString('/app/gone'));

        ConfigOverride::install($this->application());

        self::assertSame('value', config('app.secret'));
        $app = $this->config()->all()['app'];
        self::assertIsArray($app);
        self::assertArrayHasKey('gone', $app);
        self::assertNull($app['gone'], 'a required marker leaves null behind');

        $this->expectException(NotFoundException::class);
        config('app.gone');
    }

    public function testExplicitMapIsNormalisedAndMarkersWin(): void
    {
        $this->useModule(['/app/name' => 'sFrom OnlineConf', '/app/other' => 's7']);
        $this->config()->set('app.name', Onlineconf::refString('/app/name', 'From config'));
        $this->config()->set('onlineconf.map', [
            'app.name' => '/app/ignored',
            'app.legacy' => '/app/other',
            'app.typed' => ['path' => '/app/other', 'type' => Ref::TYPE_INT],
        ]);
        $this->config()->set('app.legacy', 0);
        $this->config()->set('app.typed', 0);

        ConfigOverride::install($this->application());

        self::assertSame('From OnlineConf', config('app.name'), 'the marker next to the value wins over the explicit map');
        self::assertSame(7, config('app.legacy'), 'the old format still reads by the type of the fallback');
        self::assertSame(7, config('app.typed'));
        $map = config('onlineconf.map');
        self::assertIsArray($map);
        self::assertSame(['path' => '/app/name', 'type' => Ref::TYPE_STRING, 'required' => false], $map['app.name'] ?? null);
    }

    public function testReadsFromConfigLoadingAreReportedAtInstall(): void
    {
        EagerReads::record('/app/eager', Ref::TYPE_STRING, 'dflt', true, 'TREE');
        $line = __LINE__ - 1;
        EagerReads::record('/app/present', Ref::TYPE_STRING, 'dflt', false, 'TREE');
        $this->useModule(['/app/name' => 'sFrom OnlineConf']);
        $this->config()->set('onlineconf.map', ['app.name' => '/app/name']);
        $this->config()->set('onlineconf.on_missing', RecordingHandler::class);

        ConfigOverride::install($this->application());

        self::assertCount(1, RecordingHandler::$missing, 'only the read that found nothing');
        $missing = RecordingHandler::$missing[0];
        self::assertSame('', $missing->configKey, 'a read at config load time has no config key');
        self::assertSame('/app/eager', $missing->path);
        self::assertSame('dflt', $missing->fallback);
        self::assertSame('TREE', $missing->module);
        self::assertSame(__FILE__, $missing->file);
        self::assertSame($line, $missing->line);

        ConfigOverride::install($this->application());

        self::assertCount(1, RecordingHandler::$missing, 'a second install reports nothing twice');
    }
}
