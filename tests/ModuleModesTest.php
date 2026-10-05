<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Facade;
use Onlineconf\Exception\OpenException;
use Onlineconf\Laravel\ConfigOverride;
use Onlineconf\Laravel\EagerReads;
use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Laravel\ImmediateModule;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every way of reading a node × every state of the module file, with a default written as a string — what
 * env('DB_PORT') gives: an immediate read before boot and after it, a marker through config(), Ref::value(),
 * and a marker through config:cache.
 */
final class ModuleModesTest extends TestCase
{
    private const WAYS = ['immediate before boot', 'immediate after boot', 'marker through config()', 'Ref::value()', 'config:cache'];

    protected function setUp(): void
    {
        parent::setUp();
        EagerReads::flush();
        ImmediateModule::flush();
    }

    protected function tearDown(): void
    {
        putenv('ONLINECONF_DIR');
        EagerReads::flush();
        ImmediateModule::flush();
        Facade::setFacadeApplication($this->application());
        Container::setInstance($this->application());
        parent::tearDown();
    }

    /**
     * way, state, default, the expected value; null expected with "file missing, required": the boot fails.
     *
     * @return iterable<string, array{string, string, string, int|null}>
     */
    public static function cases(): iterable
    {
        foreach (self::WAYS as $way) {
            yield "$way, node present" => [$way, 'node present', '42', 7];
            yield "$way, node missing" => [$way, 'node missing', '42', 42];
            yield "$way, file missing, required" => [$way, 'file missing, required', '42', null];
            yield "$way, file missing, optional" => [$way, 'file missing, optional', '42', 42];
            yield "$way, empty default, node missing" => [$way, 'node missing', '', null];
            yield "$way, empty default, file missing, optional" => [$way, 'file missing, optional', '', null];
        }
    }

    #[DataProvider('cases')]
    public function testEveryWayInEveryState(string $way, string $state, string $default, ?int $expected): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());
        match ($state) {
            'node present' => $this->writeModule(['/p' => 's7']),
            'node missing' => $this->writeModule(['/other' => 'sx']),
            'file missing, optional' => $this->optionalModule(),
            default => null,
        };
        if ($state === 'file missing, required') {
            $this->expectException(OpenException::class);
            $this->expectExceptionMessage('set ONLINECONF_REQUIRED=false to start without it');
        }

        self::assertSame($expected, $this->read($way, $default));
    }

    private function read(string $way, string $default): mixed
    {
        return match ($way) {
            'immediate before boot' => $this->beforeBoot(static fn (): mixed => Onlineconf::getInt('/p', $default)),
            'immediate after boot' => Onlineconf::getInt('/p', $default),
            'marker through config()' => $this->throughConfig($default),
            'Ref::value()' => Onlineconf::getRefInt('/p', $default)->value(),
            default => $this->throughConfigCache($default),
        };
    }

    /**
     * @param \Closure(): mixed $read
     */
    private function beforeBoot(\Closure $read): mixed
    {
        putenv('ONLINECONF_DIR=' . $this->tempDir());
        Facade::setFacadeApplication(null);

        return $read();
    }

    private function throughConfig(string $default): mixed
    {
        $this->config()->set('app.port', Onlineconf::getRefInt('/p', $default));
        ConfigOverride::install($this->application());

        return config('app.port');
    }

    /**
     * What config:cache does in an application that installs the override, then the cached boot.
     */
    private function throughConfigCache(string $default): mixed
    {
        $base = $this->tempDir() . '/app';
        mkdir($base . '/config', 0o700, true);
        mkdir($base . '/bootstrap/cache', 0o700, true);
        file_put_contents($base . '/config/app.php', sprintf(
            "<?php return ['port' => \\Onlineconf\\Laravel\\Facades\\Onlineconf::getRefInt('/p', %s), 'env' => 'testing', 'timezone' => 'UTC'];",
            var_export($default, true),
        ));
        file_put_contents(
            $base . '/config/logging.php',
            "<?php return ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \\Monolog\\Handler\\NullHandler::class]]];",
        );
        file_put_contents(
            $base . '/config/onlineconf.php',
            sprintf("<?php return ['dir' => %s, 'check_interval' => 0];", var_export($this->tempDir(), true)),
        );

        $caching = $this->boot($base);
        try {
            $loaded = $caching->make(Repository::class);
            assert($loaded instanceof Repository);
            file_put_contents($caching->getCachedConfigPath(), '<?php return ' . var_export($loaded->all(), true) . ';' . PHP_EOL);
        } finally {
            $caching->flush();
        }

        $cached = $this->boot($base);
        try {
            self::assertTrue($cached->configurationIsCached());
            $config = $cached->make(Repository::class);
            assert($config instanceof Repository);

            return $config->get('app.port');
        } finally {
            $cached->flush();
        }
    }

    private function boot(string $base): Application
    {
        $app = new Application($base);
        ConfigOverride::register($app);
        $app->bootstrapWith([LoadConfiguration::class]);

        return $app;
    }
}
