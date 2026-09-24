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
use Onlineconf\Laravel\ModuleManager;
use Onlineconf\Laravel\Ref;

final class ConfigOverrideTest extends TestCase
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

    public function testInstallReplacesTheRepositoryAndReadsTheMarkedNodes(): void
    {
        $this->useModule(['/app/name' => 'sFrom OnlineConf']);
        $this->config()->set('app.name', Onlineconf::getRefString('/app/name', 'From config'));
        $this->config()->set('app.missing', Onlineconf::getRefString('/app/missing', null));

        ConfigOverride::install($this->application());

        $config = $this->application()->make(Repository::class);
        self::assertInstanceOf(OverridingRepository::class, $config);
        self::assertSame($config, $this->application()->make('config'));
        self::assertSame('From OnlineConf', $config->get('app.name'));
        self::assertSame('From OnlineConf', config('app.name'));
        self::assertNull(config('app.missing'));
    }

    public function testManagerIsSharedWithTheProviderSoFakesReachConfig(): void
    {
        $this->useModule(['/app/name' => 'sreal']);
        $this->config()->set('app.name', Onlineconf::getRefString('/app/name', 'From config'));

        ConfigOverride::install($this->application());

        self::assertSame('real', config('app.name'));
        self::assertSame($this->application()->make(ModuleManager::class), $this->application()->make(ModuleManager::class));

        Onlineconf::fake(['/app/name' => 'fake']);

        self::assertSame('fake', config('app.name'));
        self::assertSame('fake', Onlineconf::getString('/app/name', ''));
    }

    public function testAConfigurationWithoutMarkersIsLeftAlone(): void
    {
        $before = $this->config();

        ConfigOverride::install($this->application());

        self::assertSame($before, $this->config());
        self::assertSame([], config('onlineconf.map'));
    }

    public function testMarkersBecomeMapEntriesAndLeaveTheirFallbacksBehind(): void
    {
        $this->useModule([
            '/app/name' => 'sFrom OnlineConf',
            '/app/workers' => 's8',
            '/app/ttl' => 's1m',
        ]);
        $this->config()->set('app.name', Onlineconf::getRefString('/app/name', 'From config'));
        $this->config()->set('services.queue', [
            'workers' => Onlineconf::getRefInt('/app/workers', 2),
            'ttl' => Onlineconf::getRefDuration('/app/ttl', 5.0),
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
            'the map is derived from the markers and written back for the tooling',
        );

        $config = $this->config();
        self::assertInstanceOf(OverridingRepository::class, $config);
        $all = $config->all();
        self::assertIsArray($all['app']);
        self::assertSame('From config', $all['app']['name'], 'config:cache sees the fallback, never a marker');
    }

    public function testAMarkerUnderANumericKey(): void
    {
        $this->useModule(['/servers/0/host' => 'sfrom OnlineConf']);
        $this->config()->set('servers', [
            ['host' => Onlineconf::getRefString('/servers/0/host', 'from config'), 'port' => 5432],
        ]);

        ConfigOverride::install($this->application());

        self::assertSame('from OnlineConf', config('servers.0.host'));
        self::assertSame([['host' => 'from OnlineConf', 'port' => 5432]], config('servers'), 'the list keeps its shape');
        $map = config('onlineconf.map');
        self::assertIsArray($map);
        self::assertSame(['path' => '/servers/0/host', 'type' => Ref::TYPE_STRING, 'required' => false], $map['servers.0.host'] ?? null);
    }

    public function testRequiredMarker(): void
    {
        $this->useModule(['/app/secret' => 'svalue']);
        $this->config()->set('app.secret', Onlineconf::requireRefString('/app/secret'));
        $this->config()->set('app.gone', Onlineconf::requireRefString('/app/gone'));

        ConfigOverride::install($this->application());

        self::assertSame('value', config('app.secret'));
        $app = $this->config()->all()['app'];
        self::assertIsArray($app);
        self::assertArrayHasKey('gone', $app);
        self::assertNull($app['gone'], 'a required marker leaves null behind');

        $this->expectException(NotFoundException::class);
        config('app.gone');
    }

    public function testRegisterHooksIntoTheBootstrapSequence(): void
    {
        $base = $this->tempDir() . '/app';
        mkdir($base . '/config', 0o700, true);
        file_put_contents(
            $base . '/config/app.php',
            sprintf(
                "<?php return ['name' => \\Onlineconf\\Laravel\\Facades\\Onlineconf::getRefString('/app/name', 'From config'), 'env' => 'testing', 'timezone' => 'UTC', 'locale' => 'en', 'fallback_locale' => 'en', 'providers' => [], 'aliases' => []];",
            ),
        );
        file_put_contents(
            $base . '/config/logging.php',
            "<?php return ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \\Monolog\\Handler\\NullHandler::class]]];",
        );
        $this->writeModule(['/app/name' => 'sFrom OnlineConf']);
        file_put_contents(
            $base . '/config/onlineconf.php',
            sprintf(
                "<?php return ['dir' => %s, 'module' => null, 'check_interval' => 0, 'log_channel' => null];",
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

    public function testOnlyTheReadsOfTheCurrentConfigLoadAreKept(): void
    {
        EagerReads::record('/app/first', Ref::TYPE_STRING, null);
        ConfigOverride::install($this->application());
        EagerReads::record('/app/second', Ref::TYPE_STRING, null);

        ConfigOverride::install($this->application());

        $reads = EagerReads::all();
        self::assertCount(1, $reads, 'a process that boots many applications does not grow a list of reads');
        self::assertSame('/app/second', $reads[0]->path);
    }
}
