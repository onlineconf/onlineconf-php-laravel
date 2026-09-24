<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Config;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Support\Arr;
use Onlineconf\Exception\FormatException;
use Onlineconf\Exception\InvalidJsonException;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\OpenException;
use Onlineconf\Exception\ParseException;
use Onlineconf\Laravel\CallSite;
use Onlineconf\Laravel\MissingValue;
use Onlineconf\Laravel\Ref;
use Onlineconf\Module;
use Psr\Log\LoggerInterface;

/**
 * Config repository that reads mapped keys from OnlineConf and falls back to the loaded configuration
 * for everything else: unmapped keys, keys OnlineConf does not have, values that do not parse, invalid
 * JSON, a module file that cannot be opened.
 *
 * An entry that declares a type is read with that type ({@see Ref::TYPES}); an entry without one — the 1.1
 * map format — is read with the type of the fallback (bool → getBool, int → getInt, …), so callers get the
 * type they got from config/*.php. Either way the fallback is returned as it is: it is never replaced by an
 * empty value of the declared type. A required entry must exist: the client's exception reaches the caller
 * instead of a fallback.
 */
final class OverridingRepository extends Repository
{
    /** @var array<string, array{path: string, type: string|null, required: bool}> config key → node */
    private array $map;

    /** @var array<string, list<string>> config key → mapped keys below it ("services" → ["services.mailer.host", …]) */
    private array $below = [];

    private bool $openErrorLogged = false;

    /** @var array<string, true> config keys already handed to the on_missing handler */
    private array $reported = [];

    /**
     * @param array<mixed>                      $items         the loaded configuration
     * @param array<string, array{path: string, type: string|null, required: bool}> $map config key → node,
     *                                                         as {@see MapEntry::normalize()} produces it
     * @param Closure(): Module                 $moduleFactory returns the module to read from; called on every mapped read
     * @param Closure(MissingValue): void|null  $onMissing     called once per config key and process when a mapped key is absent from OnlineConf
     */
    public function __construct(
        array $items,
        array $map,
        private readonly Closure $moduleFactory,
        private readonly LoggerInterface $logger,
        private readonly ?Closure $onMissing = null,
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
     * The map the override reads, for tooling ({@see \Onlineconf\Laravel\Console\MapCommand}).
     *
     * @return array<string, array{path: string, type: string|null, required: bool}>
     */
    public function map(): array
    {
        return $this->map;
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
     * The OnlineConf value read with the declared type, or with the type of the fallback when the entry
     * declares none; the fallback on any failure the client reports and when the key is absent (reported to
     * the on_missing handler). A required entry reads with require*, so the client's exceptions propagate.
     *
     * @param array{path: string, type: string|null, required: bool} $entry
     */
    private function override(string $key, array $entry, mixed $fallback): mixed
    {
        $module = $this->module();
        if ($module === null) {
            return $fallback;
        }
        $path = $entry['path'];
        if ($entry['required']) {
            return self::readRequired($module, $entry['type'], $path, $fallback);
        }
        if (!$module->has($path)) {
            $this->reportMissing($key, $path, $fallback, $module);

            return $fallback;
        }

        try {
            return $entry['type'] === null
                ? self::readByFallback($module, $path, $fallback)
                : $this->readTyped($module, $entry['type'], $path, $fallback);
        } catch (NotFoundException) {
            // has() and the read each check the file for updates, so the node can be gone by now; for an
            // optional key that is a miss like any other, not an exception.
            $this->reportMissing($key, $path, $fallback, $module);

            return $fallback;
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
     * @throws InvalidJsonException
     */
    private static function readByFallback(Module $module, string $path, mixed $fallback): mixed
    {
        return match (true) {
            is_bool($fallback) => $module->getBool($path, $fallback),
            is_int($fallback) => $module->getInt($path, $fallback),
            is_float($fallback) => $module->getFloat($path, $fallback),
            is_string($fallback) => $module->getString($path, $fallback),
            is_array($fallback) => $module->getArray($path, $fallback),
            default => $module->get($path, $fallback),
        };
    }

    /**
     * The node read with the declared type. The client's require* is used, because a get* would need a default
     * of that very type and the fallback from config/*.php may be of any type — including null. A value that
     * does not parse as the declared type is a warning (in the client's own wording) and the real fallback.
     *
     * @throws InvalidJsonException|NotFoundException when the node vanished since the caller checked
     */
    private function readTyped(Module $module, string $type, string $path, mixed $fallback): mixed
    {
        try {
            return self::readRequired($module, $type, $path, $fallback);
        } catch (FormatException|ParseException $e) {
            $this->logger->warning('onlineconf: ' . $e->getMessage());

            return $fallback;
        }
    }

    /**
     * An entry with no declared type — the 1.1 map format — follows the type of the fallback here too.
     *
     * @throws \Onlineconf\Exception\OnlineconfException
     */
    private static function readRequired(Module $module, ?string $type, string $path, mixed $fallback): mixed
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
            Ref::TYPE_RAW => $module->require($path),
            default => self::requireByFallback($module, $path, $fallback),
        };
    }

    /**
     * @throws \Onlineconf\Exception\OnlineconfException
     */
    private static function requireByFallback(Module $module, string $path, mixed $fallback): mixed
    {
        return match (true) {
            is_bool($fallback) => $module->requireBool($path),
            is_int($fallback) => $module->requireInt($path),
            is_float($fallback) => $module->requireFloat($path),
            is_string($fallback) => $module->requireString($path),
            is_array($fallback) => $module->requireArray($path),
            default => $module->require($path),
        };
    }

    private function reportMissing(string $key, string $path, mixed $fallback, Module $module): void
    {
        if ($this->onMissing === null || isset($this->reported[$key])) {
            return;
        }
        $this->reported[$key] = true;
        ($this->onMissing)(new MissingValue($key, $path, $fallback, $module->name(), CallSite::frames()));
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
