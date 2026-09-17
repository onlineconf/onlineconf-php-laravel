<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Config;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Support\Arr;
use Onlineconf\Exception\InvalidJsonException;
use Onlineconf\Exception\OpenException;
use Onlineconf\Module;
use Psr\Log\LoggerInterface;

/**
 * Config repository that reads mapped keys from OnlineConf and falls back to the loaded configuration
 * for everything else: unmapped keys, keys OnlineConf does not have, values that do not parse, invalid
 * JSON, a module file that cannot be opened.
 *
 * The OnlineConf value is read with the type of the fallback (bool → getBool, int → getInt, …), so callers
 * get the type they got from config/*.php.
 */
final class OverridingRepository extends Repository
{
    /** @var array<string, string> config key → OnlineConf path */
    private array $map;

    /** @var array<string, list<string>> config key → mapped keys below it ("services" → ["services.mailer.host", …]) */
    private array $below = [];

    private bool $openErrorLogged = false;

    /**
     * @param array<mixed>          $items         the loaded configuration
     * @param array<string, string> $map           config key → OnlineConf path
     * @param Closure(): Module     $moduleFactory returns the module to read from; called on every mapped read
     */
    public function __construct(
        array $items,
        array $map,
        private readonly Closure $moduleFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($items);
        $this->map = $map;
        $this->index();
    }

    /**
     * @param array<array-key, mixed>|string $key
     */
    public function get($key, $default = null): mixed
    {
        if (is_array($key)) {
            return $this->getMany($key);
        }

        $value = parent::get($key, $default);
        if (isset($this->map[$key])) {
            return $this->override($this->map[$key], $value);
        }
        if (isset($this->below[$key]) && is_array($value)) {
            foreach ($this->below[$key] as $mapped) {
                $sub = substr($mapped, strlen($key) + 1);
                $override = $this->override($this->map[$mapped], Arr::get($value, $sub));
                // No phantom keys: a descendant that is neither in the configuration nor in OnlineConf stays absent.
                if ($override !== null || Arr::has($value, $sub)) {
                    Arr::set($value, $sub, $override);
                }
            }
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $keys
     *
     * @return array<string, mixed>
     */
    public function getMany($keys): array
    {
        $config = [];
        foreach ($keys as $index => $default) {
            $key = is_numeric($index) ? $default : $index;
            if (!is_string($key)) {
                continue;
            }
            $config[$key] = $this->get($key, is_numeric($index) ? null : $default);
        }

        return $config;
    }

    /**
     * The loaded configuration without OnlineConf substitution: config:cache serialises this, so only the
     * fallbacks are ever written to disk.
     *
     * @return array<mixed>
     */
    public function all(): array
    {
        return parent::all();
    }

    /**
     * @param string $key
     */
    public function has($key): bool
    {
        return parent::has($key) || (isset($this->map[$key]) && $this->module()?->has($this->map[$key]) === true);
    }

    /**
     * An explicit runtime write wins: the key, every mapped key below it and every mapped key above it
     * leave the map.
     *
     * @param array<mixed>|string $key
     */
    public function set($key, $value = null): void
    {
        parent::set($key, $value);
        foreach (is_array($key) ? array_keys($key) : [$key] as $written) {
            $this->unmap((string) $written);
        }
        $this->index();
    }

    private function unmap(string $key): void
    {
        $prefix = $key . '.';
        foreach (array_keys($this->map) as $mapped) {
            if ($mapped === $key || str_starts_with($mapped, $prefix) || str_starts_with($key, $mapped . '.')) {
                unset($this->map[$mapped]);
            }
        }
    }

    private function index(): void
    {
        $this->below = [];
        foreach (array_keys($this->map) as $mapped) {
            $segments = explode('.', $mapped);
            for ($depth = 1, $count = count($segments); $depth < $count; $depth++) {
                $this->below[implode('.', array_slice($segments, 0, $depth))][] = $mapped;
            }
        }
    }

    /**
     * The OnlineConf value read with the type of the fallback; the fallback on any failure the client reports.
     */
    private function override(string $path, mixed $fallback): mixed
    {
        $module = $this->module();
        if ($module === null) {
            return $fallback;
        }

        try {
            return match (true) {
                is_bool($fallback) => $module->getBool($path, $fallback),
                is_int($fallback) => $module->getInt($path, $fallback),
                is_float($fallback) => $module->getFloat($path, $fallback),
                is_string($fallback) => $module->getString($path, $fallback),
                is_array($fallback) => $module->getArray($path, $fallback),
                default => $module->get($path, $fallback),
            };
        } catch (InvalidJsonException $e) {
            $this->logger->error(sprintf(
                'OnlineConf value at %s is not valid JSON, config() falls back to the loaded configuration: %s',
                $path,
                $e->getMessage(),
            ));

            return $fallback;
        }
    }

    /**
     * The module, or null when it cannot be opened; the error is logged once until the module opens again.
     */
    private function module(): ?Module
    {
        try {
            $module = ($this->moduleFactory)();
        } catch (OpenException $e) {
            if (!$this->openErrorLogged) {
                $this->openErrorLogged = true;
                $this->logger->error('OnlineConf is unavailable, config() falls back to the loaded configuration: ' . $e->getMessage());
            }

            return null;
        }
        $this->openErrorLogged = false;

        return $module;
    }
}
