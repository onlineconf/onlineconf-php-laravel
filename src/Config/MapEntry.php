<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Config;

use Illuminate\Support\Arr;
use Onlineconf\Laravel\Ref;
use Onlineconf\Laravel\Transform;

/**
 * Reads the derived map back out of the configuration, where {@see \Onlineconf\Laravel\ConfigOverride} wrote
 * it (possibly through config:cache), for {@see \Onlineconf\Laravel\Console\MapCommand}. Only entries of the
 * shape a {@see Ref} produces survive; anything else is dropped, so a hand-edited "onlineconf.map" cannot
 * break the command.
 *
 * @phpstan-import-type Encoded from Transform
 * @phpstan-type Entry array{path: string, type: string, required: bool, fallback: mixed, transform: Encoded|null}
 */
final class MapEntry
{
    /**
     * @param array<mixed> $items the loaded configuration: a map written by 1.2 carries no fallback, so it is
     *                            taken from the configured value (which 1.2 never transformed)
     *
     * @return array<string, Entry>
     */
    public static function normalize(mixed $map, array $items = []): array
    {
        $entries = [];
        foreach (is_array($map) ? $map : [] as $key => $entry) {
            if (!is_string($key) || $key === '' || !is_array($entry)) {
                continue;
            }
            $path = $entry['path'] ?? null;
            $type = $entry['type'] ?? null;
            if (!is_string($path) || $path === '' || !is_string($type) || !in_array($type, Ref::TYPES, true)) {
                continue;
            }
            $transform = $entry['transform'] ?? null;
            if ($transform !== null && !Transform::isEncoded($transform)) {
                continue;
            }
            $entries[$key] = [
                'path' => $path,
                'type' => $type,
                'required' => (bool) ($entry['required'] ?? false),
                'fallback' => array_key_exists('fallback', $entry) ? $entry['fallback'] : Arr::get($items, $key),
                'transform' => $transform,
            ];
        }

        return $entries;
    }
}
