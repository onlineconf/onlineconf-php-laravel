<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Onlineconf\Laravel\ConfigOverride;
use Onlineconf\Laravel\EagerReads;

/**
 * config:cache writes the loaded configuration with var_export(). Markers survive it through
 * Ref::__set_state() (the caching application does not install the override), an immediate read is baked in
 * as the plain value it returned, and the override works on top of the cached array.
 */
final class ConfigCacheTest extends TestCase
{
    private ?string $probe = null;

    private ?string $published = null;

    protected function setUp(): void
    {
        parent::setUp();
        EagerReads::flush();
    }

    protected function tearDown(): void
    {
        foreach ([$this->probe, $this->published] as $file) {
            if ($file !== null && file_exists($file)) {
                unlink($file);
            }
        }
        $this->probe = null;
        $this->published = null;
        Artisan::call('config:clear');
        putenv('ONLINECONF_DIR');
        self::setOverride(null);
        EagerReads::flush();
        Facade::setFacadeApplication($this->application());
        Container::setInstance($this->application());
        parent::tearDown();
    }

    public function testCachedConfigurationKeepsTheMarkersAndBakesInTheImmediateReads(): void
    {
        $this->writeModule(['/probe/lazy' => 'sfrom OnlineConf', '/probe/eager' => 'sread at load time']);
        putenv('ONLINECONF_DIR=' . $this->tempDir());
        self::setOverride('true');
        $base = $this->application()->basePath();
        // The caching application is a plain Laravel skeleton: it discovers no package, so it needs the
        // published config file, exactly as a real application does after vendor:publish.
        $this->published = $base . '/config/onlineconf.php';
        self::assertFileDoesNotExist($this->published);
        file_put_contents($this->published, sprintf(
            "<?php return ['dir' => %s, 'module' => null, 'check_interval' => 0, 'log_channel' => null, 'config_override' => true, 'on_missing' => null, 'map' => []];",
            var_export($this->tempDir(), true),
        ));
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
                ['path' => '/probe/lazy', 'type' => 'string', 'required' => false],
                $map['onlineconf_probe.lazy'] ?? null,
            );
            self::assertSame([], EagerReads::all(), 'config files did not run, so onlineconf:map has no immediate reads');
        } finally {
            $app->flush();
        }
    }

    /**
     * env() reads $_ENV and $_SERVER always and getenv() only while Dotenv's putenv adapter is on, which
     * Testbench turns off while it builds the application; a real .env file feeds all three.
     */
    private static function setOverride(?string $value): void
    {
        if ($value === null) {
            putenv('ONLINECONF_CONFIG_OVERRIDE');
            unset($_ENV['ONLINECONF_CONFIG_OVERRIDE'], $_SERVER['ONLINECONF_CONFIG_OVERRIDE']);

            return;
        }
        putenv('ONLINECONF_CONFIG_OVERRIDE=' . $value);
        $_ENV['ONLINECONF_CONFIG_OVERRIDE'] = $value;
        $_SERVER['ONLINECONF_CONFIG_OVERRIDE'] = $value;
    }
}
