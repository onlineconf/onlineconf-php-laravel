<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use Onlineconf\Cdb\CdbWriter;
use Onlineconf\Laravel\OnlineconfServiceProvider;
use Orchestra\Testbench\TestCase as TestbenchTestCase;

abstract class TestCase extends TestbenchTestCase
{
    private ?string $tempDir = null;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OnlineconfServiceProvider::class];
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            self::removeDir($this->tempDir);
            $this->tempDir = null;
        }
        parent::tearDown();
    }

    private static function removeDir(string $dir): void
    {
        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    protected function application(): \Illuminate\Foundation\Application
    {
        $app = $this->app;
        assert($app !== null);

        return $app;
    }

    protected function config(): Repository
    {
        $config = $this->application()->make(Repository::class);
        assert($config instanceof Repository);

        return $config;
    }

    /**
     * A private temporary directory for module files, removed in tearDown().
     */
    protected function tempDir(): string
    {
        if ($this->tempDir === null) {
            $dir = sys_get_temp_dir() . '/onlineconf-laravel-' . bin2hex(random_bytes(6));
            mkdir($dir, 0o700);
            $this->tempDir = $dir;
        }

        return $this->tempDir;
    }

    /**
     * Writes "<tempDir>/<name>.cdb" and returns its path. Calling it again for the same name replaces the
     * file atomically, as onlineconf-updater does.
     *
     * @param array<string, string> $raw path → raw value with the type byte
     */
    protected function writeModule(array $raw, string $name = 'TREE'): string
    {
        $file = $this->tempDir() . '/' . $name . '.cdb';
        CdbWriter::write($file, $raw);

        return $file;
    }

    /**
     * Writes the module and points the package at the temporary directory.
     *
     * @param array<string, string> $raw
     */
    protected function useModule(array $raw, string $name = 'TREE'): string
    {
        $file = $this->writeModule($raw, $name);
        $this->config()->set('onlineconf.dir', $this->tempDir());

        return $file;
    }
}
