<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Laravel\SerializableClosure\SerializableClosure;
use Onlineconf\Laravel\Config\OverridingRepository;
use Onlineconf\Laravel\ConfigOverride;
use Onlineconf\Laravel\Console\MapCommand;
use Onlineconf\Laravel\EagerReads;
use Onlineconf\Laravel\Transform;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * config:cache writes the loaded configuration with var_export(). An application that installs the override
 * caches the fallbacks and the derived map, and the cached boot installs the override from that map. One that
 * does not install it caches the markers themselves, which Ref::__set_state() restores. Either way an
 * immediate read is baked in as the plain value it returned.
 */
final class ConfigCacheTest extends TestCase
{
    private ?string $probe = null;

    protected function setUp(): void
    {
        parent::setUp();
        EagerReads::flush();
    }

    protected function tearDown(): void
    {
        if ($this->probe !== null && file_exists($this->probe)) {
            unlink($this->probe);
        }
        $this->probe = null;
        Artisan::call('config:clear');
        putenv('ONLINECONF_DIR');
        EagerReads::flush();
        Facade::setFacadeApplication($this->application());
        Container::setInstance($this->application());
        parent::tearDown();
    }

    public function testCachedConfigurationKeepsTheMarkersAndBakesInTheImmediateReads(): void
    {
        $this->writeModule(['/probe/lazy' => 'sfrom OnlineConf', '/probe/eager' => 'sread at load time']);
        putenv('ONLINECONF_DIR=' . $this->tempDir());
        $base = $this->application()->basePath();
        $this->probe = $base . '/config/onlineconf_probe.php';
        file_put_contents($this->probe, <<<'PROBE'
            <?php

            use Onlineconf\Laravel\Facades\Onlineconf;

            return [
                'lazy' => Onlineconf::getRefString('/probe/lazy', 'from config'),
                'eager' => Onlineconf::getString('/probe/eager', 'default'),
            ];
            PROBE);

        self::assertSame(0, Artisan::call('config:cache'));
        $cache = $this->application()->getCachedConfigPath();
        self::assertFileExists($cache);
        $contents = file_get_contents($cache);
        self::assertIsString($contents);
        self::assertStringContainsString('Onlineconf\\Laravel\\Ref::__set_state', $contents, 'the marker is cached, not its value');
        self::assertStringContainsString('read at load time', $contents, 'the immediate read is baked in');

        // In this test config:cache ran in the same PHP process and its skeleton does not install the override,
        // so its immediate read is still registered; a real config:cache is a separate process.
        EagerReads::flush();
        $app = new Application($base);
        ConfigOverride::register($app);
        $app->bootstrapWith([LoadConfiguration::class]);

        try {
            $config = $app->make(Repository::class);
            assert($config instanceof Repository);
            self::assertTrue($app->configurationIsCached(), 'the fresh application boots from the cache');
            self::assertSame('from OnlineConf', $config->get('onlineconf_probe.lazy'), 'the marker still reads OnlineConf');
            self::assertSame('read at load time', $config->get('onlineconf_probe.eager'), 'fixed when the cache was written');
            $map = $config->get('onlineconf.map');
            self::assertIsArray($map);
            self::assertSame(
                ['path' => '/probe/lazy', 'type' => 'string', 'required' => false, 'fallback' => 'from config', 'transform' => null],
                $map['onlineconf_probe.lazy'] ?? null,
            );
            self::assertSame([], EagerReads::all(), 'config files did not run, so onlineconf:map has no immediate reads');
        } finally {
            $app->flush();
        }
    }

    public function testAnApplicationThatInstallsTheOverrideKeepsItAcrossTheCache(): void
    {
        $this->writeModule(['/probe/lazy' => 'sfrom OnlineConf']);
        $base = $this->tempDir() . '/app';
        mkdir($base . '/config', 0o700, true);
        mkdir($base . '/bootstrap/cache', 0o700, true);
        file_put_contents($base . '/config/app.php', <<<'APP'
            <?php

            use Onlineconf\Laravel\Facades\Onlineconf;

            return [
                'name' => Onlineconf::getRefString('/probe/lazy', 'from config'),
                'env' => 'testing',
                'timezone' => 'UTC',
            ];
            APP);
        file_put_contents(
            $base . '/config/logging.php',
            "<?php return ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \\Monolog\\Handler\\NullHandler::class]]];",
        );
        file_put_contents(
            $base . '/config/onlineconf.php',
            sprintf("<?php return ['dir' => %s, 'check_interval' => 0];", var_export($this->tempDir(), true)),
        );

        // What config:cache does in an application whose bootstrap/app.php registers the override: it boots
        // that application and writes all() of its configuration with var_export().
        $caching = $this->boot($base);
        $cache = $caching->getCachedConfigPath();
        $loaded = $caching->make(Repository::class);
        assert($loaded instanceof Repository);
        file_put_contents($cache, '<?php return ' . var_export($loaded->all(), true) . ';' . PHP_EOL);
        $caching->flush();
        $contents = file_get_contents($cache);
        self::assertIsString($contents);
        self::assertStringNotContainsString('__set_state', $contents, 'the caching application resolved its markers');

        $cached = $this->boot($base);
        try {
            self::assertTrue($cached->configurationIsCached());
            $config = $cached->make(Repository::class);
            self::assertInstanceOf(OverridingRepository::class, $config, 'the override is installed from the cached map');
            self::assertSame('from OnlineConf', $config->get('app.name'));

            $command = new MapCommand();
            $command->setLaravel($cached);
            $output = new BufferedOutput();
            self::assertSame(0, $command->run(new ArrayInput(['--json' => true]), $output));
            $listed = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($listed);
            self::assertSame(
                ['app.name' => ['path' => '/probe/lazy', 'type' => 'string', 'required' => false, 'fallback' => 'from config', 'transform' => false]],
                $listed['map'],
            );
        } finally {
            $cached->flush();
        }
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function signers(): array
    {
        return [
            // php artisan config:cache: the outer application's encryption provider has set app.key as the
            // closure signer; the cached boot of the next process installs the override before any provider.
            'signed while caching, read before providers' => ['base64:caching-key', null],
            'no signer anywhere' => [null, null],
            'the same signer on both ends' => ['base64:shared-key', 'base64:shared-key'],
        ];
    }

    #[DataProvider('signers')]
    public function testAClosureTransformSurvivesTheCache(?string $cachingSigner, ?string $readingSigner): void
    {
        $this->writeModule(['/probe/hosts' => 'sx, y, z']);
        $base = $this->tempDir() . '/app';
        mkdir($base . '/config', 0o700, true);
        mkdir($base . '/bootstrap/cache', 0o700, true);
        file_put_contents($base . '/config/app.php', <<<'APP'
            <?php

            use Onlineconf\Laravel\Facades\Onlineconf;

            $separator = ',';

            return [
                'hosts' => Onlineconf::getRefString(
                    '/probe/hosts',
                    'a, b',
                    static fn (?string $value): array => array_map('trim', explode($separator, (string) $value)),
                ),
                'env' => 'testing',
                'timezone' => 'UTC',
            ];
            APP);
        file_put_contents(
            $base . '/config/logging.php',
            "<?php return ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \\Monolog\\Handler\\NullHandler::class]]];",
        );
        file_put_contents(
            $base . '/config/onlineconf.php',
            sprintf("<?php return ['dir' => %s, 'check_interval' => 0];", var_export($this->tempDir(), true)),
        );

        try {
            SerializableClosure::setSecretKey($cachingSigner);
            $caching = $this->boot($base);
            $loaded = $caching->make(Repository::class);
            assert($loaded instanceof Repository);
            self::assertSame(['x', 'y', 'z'], $loaded->get('app.hosts'));
            // What ConfigCacheCommand does: write with var_export(), then require the file to check it.
            $cache = $caching->getCachedConfigPath();
            file_put_contents($cache, '<?php return ' . var_export($loaded->all(), true) . ';' . PHP_EOL);
            $caching->flush();
            self::assertIsArray(require $cache, 'Laravel\'s "configuration files are not serializable" check passes');

            SerializableClosure::setSecretKey($readingSigner);
            $cached = $this->boot($base);
            try {
                self::assertTrue($cached->configurationIsCached());
                $config = $cached->make(Repository::class);
                assert($config instanceof Repository);
                self::assertSame(['x', 'y', 'z'], $config->get('app.hosts'), 'the node value through the cached closure');
                $all = $config->all();
                self::assertIsArray($all['app']);
                self::assertSame(['a', 'b'], $all['app']['hosts'], 'the cached fallback is already shaped');
            } finally {
                $cached->flush();
            }
        } finally {
            SerializableClosure::setSecretKey(null);
        }
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function signersAroundTheBoot(): array
    {
        return [
            // EncryptionServiceProvider sets app.key as the signer after the configuration is loaded.
            'Laravel sets its signer after the boot' => [null, 'base64:app-key'],
            'a signer left over from an earlier boot' => ['base64:earlier-key', null],
        ];
    }

    #[DataProvider('signersAroundTheBoot')]
    public function testANonStaticClosureInAConfigFileIgnoresLaravelsSigner(?string $beforeBoot, ?string $afterBoot): void
    {
        $this->writeModule(['/probe/hosts' => 'sx, y']);
        $base = $this->tempDir() . '/app';
        mkdir($base . '/config', 0o700, true);
        // The consumer's form: a plain (non-static) arrow function in a config file loaded by LoadConfiguration.
        file_put_contents($base . '/config/app.php', <<<'APP'
            <?php

            use Onlineconf\Laravel\Facades\Onlineconf;

            return [
                'hosts' => Onlineconf::getRefString('/probe/hosts', 'a, b', fn (?string $value): array => array_map('trim', explode(',', (string) $value))),
                'env' => 'testing',
                'timezone' => 'UTC',
            ];
            APP);
        file_put_contents(
            $base . '/config/logging.php',
            "<?php return ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \\Monolog\\Handler\\NullHandler::class]]];",
        );
        file_put_contents(
            $base . '/config/onlineconf.php',
            sprintf("<?php return ['dir' => %s, 'check_interval' => 0];", var_export($this->tempDir(), true)),
        );

        try {
            SerializableClosure::setSecretKey($beforeBoot);
            $app = $this->boot($base);
            SerializableClosure::setSecretKey($afterBoot);
            try {
                $config = $app->make(Repository::class);
                assert($config instanceof Repository);
                self::assertSame(['x', 'y'], $config->get('app.hosts'));
                $map = $config->get('onlineconf.map');
                self::assertIsArray($map);
                $entry = $map['app.hosts'] ?? null;
                self::assertIsArray($entry);
                $transform = $entry['transform'] ?? null;
                self::assertTrue(Transform::isEncoded($transform));
                self::assertSame(['p', 'q'], Transform::decode($transform, 'app.hosts', '/probe/hosts')('p, q'), 'decodable under any signer');
            } finally {
                $app->flush();
            }
        } finally {
            SerializableClosure::setSecretKey(null);
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
