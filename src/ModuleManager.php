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

    public function __construct(
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
        private readonly int $checkInterval,
    ) {
    }

    /**
     * The default module for null, otherwise a module by name ("TREE" → "<dir>/TREE.cdb") or by file path.
     *
     * @throws OpenException when the file cannot be opened as CDB
     */
    public function module(?string $name = null): Module
    {
        $key = $this->key($name);

        return $this->modules[$key] ??= new Module(new CdbSource($key), $this->logger, $this->checkInterval);
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
        $this->modules[$this->key($name)] = new Module($source, $this->logger, 0);

        return $source;
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
