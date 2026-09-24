<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Onlineconf\Exception\OpenException;
use Onlineconf\Module;
use Onlineconf\Settings;
use Onlineconf\Source\ArraySource;
use Onlineconf\Source\CdbSource;
use Psr\Log\LoggerInterface;

/**
 * Registry of opened modules: the same file always yields the same {@see Module}.
 *
 * The container equivalent of the client's static {@see \Onlineconf\Onlineconf} registry.
 */
final class ModuleManager
{
    /** @var array<string, Module> modules by resolved file path */
    private array $modules = [];

    /** @var array<string, OpenException> failed opens by resolved file path, rethrown instead of reopening */
    private array $failures = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
        private readonly int $checkInterval,
    ) {
    }

    /**
     * The default module for null, otherwise a module by name ("TREE" → "<dir>/TREE.cdb") or by file path.
     *
     * A file that cannot be opened is remembered for the life of the manager — the process, or the container
     * under Octane — so a machine without OnlineConf pays one failed open, noted once at debug level, and
     * every later call rethrows the same exception without touching the disk.
     *
     * @throws OpenException when the file cannot be opened as CDB
     */
    public function module(?string $name = null): Module
    {
        $key = $this->key($name);
        if (isset($this->modules[$key])) {
            return $this->modules[$key];
        }
        if (isset($this->failures[$key])) {
            throw $this->failures[$key];
        }

        try {
            return $this->modules[$key] = new Module(self::open($key), $this->logger, $this->checkInterval);
        } catch (OpenException $e) {
            $this->failures[$key] = $e;
            $this->logger->debug(sprintf(
                'OnlineConf module %s cannot be opened, not trying again in this process: %s',
                $key,
                $e->getMessage(),
            ));

            throw $e;
        }
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    /**
     * Replaces the module (the default one for null) with an in-memory module built from PHP values, for tests.
     * The returned source can be changed later with {@see ArraySource::replaceValues()}; changes are visible
     * on the next read.
     *
     * @param array<string, string|int|float|bool|array<mixed>|null> $values path → PHP value
     */
    public function fake(array $values = [], ?string $name = null): ArraySource
    {
        $source = ArraySource::fromValues($values);
        $key = $this->key($name);
        unset($this->failures[$key]);
        $this->modules[$key] = new Module($source, $this->logger, 0);

        return $source;
    }

    /**
     * The client's CDB source for an existing file. A file that is not there is reported here, before the
     * client's @fopen() runs: a suppressed warning is still a warning to error handlers such as Collision's,
     * and a machine without OnlineConf would raise one in every test that boots the application.
     *
     * @throws OpenException when the file does not exist or the client cannot open it
     */
    public static function open(string $file): CdbSource
    {
        if (!is_file($file)) {
            throw new OpenException($file . ': no such file');
        }

        return new CdbSource($file);
    }

    /**
     * Registry key: the real path of the module file, or the unresolved name when the file does not exist.
     */
    private function key(?string $name): string
    {
        $file = $this->settings->fileName($name ?? $this->settings->module);
        $key = realpath($file);

        return $key === false ? $file : $key;
    }
}
