<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Config;

use Onlineconf\Laravel\Ref;

/**
 * Reads the derived map back out of the configuration, where {@see \Onlineconf\Laravel\ConfigOverride} wrote
 * it (possibly through config:cache), for {@see \Onlineconf\Laravel\Console\MapCommand}. Only entries of the
 * shape a {@see Ref} produces survive; anything else is dropped, so a hand-edited "onlineconf.map" cannot
 * break the command.
 */
final class MapEntry
{
    /**
     * @return array<string, array{path: string, type: string, required: bool}>
     */
    public static function normalize(mixed $map): array
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
            $entries[$key] = ['path' => $path, 'type' => $type, 'required' => (bool) ($entry['required'] ?? false)];
        }

        return $entries;
    }
}
