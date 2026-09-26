<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Config;

use ArrayObject;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Support\Arr;
use Onlineconf\Exception\FormatException;
use Onlineconf\Exception\InvalidJsonException;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\OpenException;
use Onlineconf\Exception\ParseException;
use Onlineconf\Laravel\Ref;
use Onlineconf\Laravel\Transform;
use Onlineconf\Module;
use Psr\Log\LoggerInterface;

/**
 * Config repository that reads the nodes the {@see Ref} markers declare and falls back to the loaded
 * configuration for everything else: unmapped keys, nodes OnlineConf does not have, values that do not parse
 * and a module file that cannot be opened.
 *
 * Each node is read with the type its marker declares ({@see Ref::TYPES}); the fallback is returned as it is
 * and is never replaced by an empty value of that type. A required marker must find its node, in a module that
 * opens: the client's exception reaches the caller instead of a fallback.
 *
 * A marker's transform is applied to the node value and memoised per config key until the module version
 * changes; the fallback in the configuration is already shaped ({@see \Onlineconf\Laravel\ConfigOverride}).
 *
 * @phpstan-import-type Entry from MapEntry
 */
final class OverridingRepository extends Repository
{
    /** @var array<string, Entry> config key → node */
    private array $map;

    /**
     * @var ArrayObject<string, array{Module, string, mixed}> config key → the module and version a value was
     *      shaped for, and the value; an object, so the clones Octane makes per request share it
     */
    private readonly ArrayObject $shaped;

    /** @var array<string, list<string>> config key → mapped keys below it ("services" → ["services.mailer.host", …]) */
    private array $below = [];

    /**
     * @param array<mixed>                                                    $items         the loaded configuration
     * @param array<string, Entry>                                          $map           config key → node
     * @param Closure(): Module                                               $moduleFactory returns the module to read from; called on every mapped read
     * @param array<string, callable>                                         $transforms    config key → its transform, decoded by the installer
     */
    public function __construct(
        array $items,
        array $map,
        private readonly Closure $moduleFactory,
        private readonly LoggerInterface $logger,
        private readonly array $transforms = [],
    ) {
        parent::__construct($items);
        $this->map = $map;
        /** @var ArrayObject<string, array{Module, string, mixed}> $shaped */
        $shaped = new ArrayObject();
        $this->shaped = $shaped;
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
            return $this->override($key, $this->map[$key], $value);
        }
        if (isset($this->below[$key]) && is_array($value)) {
            foreach ($this->below[$key] as $mapped) {
                $sub = substr($mapped, strlen($key) + 1);
                $override = $this->override($mapped, $this->map[$mapped], Arr::get($value, $sub));
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
        $entry = $this->map[$key] ?? null;

        return parent::has($key) || ($entry !== null && $this->module()?->has($entry['path']) === true);
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
     * The node read with the type its marker declares, through its transform. A node the tree does not have
     * is the normal state — the value from config/*.php is the default — so it is a silent fallback; a value
     * that does not parse is a warning in the client's own wording, and also a fallback. A required marker
     * gets neither: its exceptions reach the caller, OpenException included.
     *
     * @param Entry $entry
     */
    private function override(string $key, array $entry, mixed $fallback): mixed
    {
        $path = $entry['path'];
        if ($entry['required']) {
            // Without a module a required node cannot be satisfied either: the OpenException propagates.
            $module = ($this->moduleFactory)();

            return $this->shape($key, $module, self::read($module, $entry['type'], $path));
        }
        $module = $this->module();
        if ($module === null) {
            return $fallback;
        }

        try {
            $value = self::read($module, $entry['type'], $path);
        } catch (NotFoundException) {
            return $fallback;
        } catch (FormatException|ParseException $e) {
            $this->logger->warning('onlineconf: ' . $e->getMessage());

            return $fallback;
        } catch (InvalidJsonException $e) {
            $this->logger->error(sprintf(
                'OnlineConf value at %s is not valid JSON, config() falls back to the loaded configuration: %s',
                $path,
                $e->getMessage(),
            ));

            return $fallback;
        }

        return $this->shape($key, $module, $value);
    }

    /**
     * The node value through the marker's transform, run once per module and version: the version changes
     * whenever the module reloads, which is the only time the value can change.
     */
    private function shape(string $key, Module $module, mixed $value): mixed
    {
        $transform = $this->transforms[$key] ?? null;
        if ($transform === null) {
            return $value;
        }
        $version = $module->version();
        $shaped = $this->shaped[$key] ?? null;
        if ($shaped !== null && $shaped[0] === $module && $shaped[1] === $version) {
            return $shaped[2];
        }
        $result = Transform::apply($transform, $value, $key, $this->map[$key]['path']);
        $this->shaped[$key] = [$module, $version, $result];

        return $result;
    }

    /**
     * The client's require* for the declared type: a get* would need a default of that very type, and the
     * fallback from config/*.php may be of any type — including null.
     *
     * @throws \Onlineconf\Exception\OnlineconfException
     */
    private static function read(Module $module, string $type, string $path): mixed
    {
        return match ($type) {
            Ref::TYPE_STRING => $module->requireString($path),
            Ref::TYPE_INT => $module->requireInt($path),
            Ref::TYPE_FLOAT => $module->requireFloat($path),
            Ref::TYPE_BOOL => $module->requireBool($path),
            Ref::TYPE_DURATION => $module->requireDuration($path),
            Ref::TYPE_DURATION_MS => $module->requireDurationMs($path),
            Ref::TYPE_STRINGS => $module->requireStrings($path),
            Ref::TYPE_ARRAY => $module->requireArray($path),
            default => $module->require($path),
        };
    }

    /**
     * The module, or null when there is none. The module manager remembers a failed open for the process and
     * notes it once, so asking it on every read costs nothing and a later fake() is seen at once.
     */
    private function module(): ?Module
    {
        try {
            return ($this->moduleFactory)();
        } catch (OpenException) {
            return null;
        }
    }
}
