<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Facade;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Laravel\Config\OverridingRepository;
use Onlineconf\Laravel\ConfigOverride;
use Onlineconf\Laravel\Console\MapCommand;
use Onlineconf\Laravel\EagerReads;
use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Laravel\ModuleManager;
use Onlineconf\Laravel\Ref;
use Onlineconf\Laravel\Tests\Support\Csv;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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
                'app.name' => ['path' => '/app/name', 'type' => Ref::TYPE_STRING, 'required' => false, 'fallback' => 'From config', 'transform' => null],
                'services.queue.workers' => ['path' => '/app/workers', 'type' => Ref::TYPE_INT, 'required' => false, 'fallback' => 2, 'transform' => null],
                'services.queue.ttl' => ['path' => '/app/ttl', 'type' => Ref::TYPE_DURATION, 'required' => false, 'fallback' => 5.0, 'transform' => null],
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
        self::assertSame(
            ['path' => '/servers/0/host', 'type' => Ref::TYPE_STRING, 'required' => false, 'fallback' => 'from config', 'transform' => null],
            $map['servers.0.host'] ?? null,
        );
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
        // The next boot loads a new configuration, as the next test of an application's suite does.
        $this->application()->instance('config', new ConfigRepository($this->config()->all()));
        EagerReads::record('/app/second', Ref::TYPE_STRING, null);

        ConfigOverride::install($this->application());

        $reads = EagerReads::all();
        self::assertCount(1, $reads, 'a process that boots many applications does not grow a list of reads');
        self::assertSame('/app/second', $reads[0]->path);
    }

    public function testALoadWithoutImmediateReadsLeavesNone(): void
    {
        EagerReads::record('/app/first', Ref::TYPE_STRING, null);
        ConfigOverride::install($this->application());

        EagerReads::trim();

        self::assertSame([], EagerReads::all(), 'the previous load does not linger');
    }

    public function testASecondInstallChangesNothing(): void
    {
        $this->useModule(['/app/name' => 'sFrom OnlineConf']);
        $this->config()->set('app.name', Onlineconf::getRefString('/app/name', 'From config'));
        ConfigOverride::install($this->application());
        $installed = $this->config();

        ConfigOverride::install($this->application());

        self::assertSame($installed, $this->config());
        self::assertSame('From OnlineConf', config('app.name'));
    }

    public function testAFakeAfterAFailedOpenReachesConfig(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());
        $this->config()->set('app.name', Onlineconf::getRefString('/app/name', 'From config'));
        ConfigOverride::install($this->application());
        self::assertSame('From config', config('app.name'), 'no module file: the fallback');

        Onlineconf::fake(['/app/name' => 'fake']);

        self::assertSame('fake', config('app.name'), 'the fake replaces the remembered failure');
    }

    public function testARepeatedInstallKeepsTheImmediateReadsOfTheLoad(): void
    {
        $base = $this->tempDir() . '/app';
        mkdir($base . '/config', 0o700, true);
        file_put_contents($base . '/config/app.php', <<<'APP'
            <?php

            use Onlineconf\Laravel\Facades\Onlineconf;

            return [
                'name' => Onlineconf::getString('/probe/eager', 'from config'),
                'env' => 'testing',
                'timezone' => 'UTC',
            ];
            APP);
        file_put_contents(
            $base . '/config/logging.php',
            "<?php return ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \\Monolog\\Handler\\NullHandler::class]]];",
        );
        putenv('ONLINECONF_DIR=' . $this->tempDir());
        // While LoadConfiguration runs in a real boot no facade application is set yet.
        Facade::setFacadeApplication(null);
        $app = new Application($base);
        try {
            ConfigOverride::register($app);
            $app->bootstrapWith([LoadConfiguration::class]);

            ConfigOverride::install($app);

            $command = new MapCommand();
            $command->setLaravel($app);
            $output = new BufferedOutput();
            self::assertSame(0, $command->run(new ArrayInput(['--json' => true]), $output));
            $listed = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($listed);
            self::assertSame(
                [['path' => '/probe/eager', 'type' => 'string', 'default' => 'from config']],
                $listed['eager'],
                'the second install is a no-op for the registry as well',
            );
        } finally {
            putenv('ONLINECONF_DIR');
            $app->flush();
            Facade::setFacadeApplication($this->application());
            Container::setInstance($this->application());
        }
    }

    public function testATransformShapesTheFallbackToo(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());
        $this->config()->set('app.hosts', Onlineconf::getRefString('/app/hosts', 'a, b', [Csv::class, 'split']));

        ConfigOverride::install($this->application());

        self::assertSame(['a', 'b'], config('app.hosts'), 'no module: the key has its final shape anyway');
        $app = $this->config()->all()['app'];
        self::assertIsArray($app);
        self::assertSame(['a', 'b'], $app['hosts'], 'config:cache writes the shaped fallback');
        $map = config('onlineconf.map');
        self::assertIsArray($map);
        self::assertSame(
            ['path' => '/app/hosts', 'type' => Ref::TYPE_STRING, 'required' => false, 'fallback' => 'a, b', 'transform' => [Csv::class, 'split']],
            $map['app.hosts'] ?? null,
            'the map keeps the raw fallback and the stored transform',
        );
    }

    public function testATransformShapesTheNodeValueAndFollowsAReplacedModuleFile(): void
    {
        $this->useModule(['/app/hosts' => 'sx, y']);
        $this->config()->set('onlineconf.check_interval', 0);
        $this->config()->set('app.hosts', Onlineconf::getRefString('/app/hosts', null, [Csv::class, 'countedSplit']));
        ConfigOverride::install($this->application());
        Csv::$calls = 0;

        self::assertSame(['x', 'y'], config('app.hosts'));
        self::assertSame(['x', 'y'], config('app.hosts'));
        self::assertSame(1, Csv::$calls, 'once per module version, not once per config() call');

        $this->writeModule(['/app/hosts' => 'sz']);

        self::assertSame(['z'], config('app.hosts'));
        self::assertSame(2, Csv::$calls, 'a replaced module file is a new version');
    }

    public function testARequiredMarkerTakesATransformToo(): void
    {
        $this->useModule(['/app/port' => 's8080']);
        $this->config()->set('app.port', Onlineconf::requireRefInt('/app/port', static fn (int $port): string => 'port ' . $port));
        $this->config()->set('app.gone', Onlineconf::requireRefInt('/app/gone', static fn (int $port): string => 'port ' . $port));

        ConfigOverride::install($this->application());

        self::assertSame('port 8080', config('app.port'));
        $app = $this->config()->all()['app'];
        self::assertIsArray($app);
        self::assertNull($app['gone'], 'a required marker has no fallback to shape');

        $this->expectException(NotFoundException::class);
        config('app.gone');
    }

    public function testAnAncestorReadGetsTheShapedDescendant(): void
    {
        $this->useModule(['/app/hosts' => 'sx, y']);
        $this->config()->set('app.hosts', Onlineconf::getRefString('/app/hosts', 'a', [Csv::class, 'split']));

        ConfigOverride::install($this->application());

        $app = config('app');
        self::assertIsArray($app);
        self::assertSame(['x', 'y'], $app['hosts']);
    }

    public function testAFailingTransformOfTheFallbackNamesTheKeyAndPathAndCanBeRetried(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());
        $this->config()->set('app.hosts', Onlineconf::getRefString('/app/hosts', null, static fn (string $value): array => [$value]));
        $before = $this->config();

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                ConfigOverride::install($this->application());
                self::fail('a transform that cannot take the fallback is a programming error');
            } catch (\RuntimeException $e) {
                self::assertStringStartsWith('OnlineConf transform of app.hosts (/app/hosts) failed: ', $e->getMessage());
                self::assertInstanceOf(\TypeError::class, $e->getPrevious());
            }
        }
        self::assertSame($before, $this->config(), 'a failed install is not remembered as done');
    }

    public function testAnInstallThatFailsAfterTheDeriveIsRetried(): void
    {
        $this->useModule(['/app/name' => 'sFrom OnlineConf']);
        $this->config()->set('app.name', Onlineconf::getRefString('/app/name', 'From config'));
        $logger = new \stdClass();
        $logger->broken = true;
        $this->application()->bind(LoggerInterface::class, static function () use ($logger): LoggerInterface {
            if ($logger->broken === true) {
                throw new \RuntimeException('no logger yet');
            }

            return new NullLogger();
        });

        try {
            ConfigOverride::install($this->application());
            self::fail('the logger cannot be resolved');
        } catch (\RuntimeException $e) {
            self::assertSame('no logger yet', $e->getMessage());
        }
        $logger->broken = false;

        ConfigOverride::install($this->application());

        self::assertInstanceOf(OverridingRepository::class, $this->config(), 'the failed install was not remembered as done');
        self::assertSame('From OnlineConf', config('app.name'));
    }
}
