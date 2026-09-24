<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Config;

use LogicException;
use Onlineconf\Laravel\Ref;

/**
 * Normalisation of the "onlineconf.map" value into the entries {@see OverridingRepository} reads:
 * "key => path" (the 1.1 format, the type follows the fallback) and
 * "key => ['path' => ..., 'type' => ..., 'required' => ...]" (what a {@see Ref} marker produces).
 *
 * Anything that is not a usable entry is dropped, so a typo in the published config file cannot break boot —
 * except a type that is not a type: a misspelled "type" would silently change how the node is read, so it
 * fails loudly instead.
 */
final class MapEntry
{
    /**
     * @return array<string, array{path: string, type: string|null, required: bool}>
     *
     * @throws LogicException when an entry declares a type that does not exist
     */
    public static function normalize(mixed $map): array
    {
        $entries = [];
        foreach (is_array($map) ? $map : [] as $key => $entry) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_string($entry)) {
                if ($entry !== '') {
                    $entries[$key] = ['path' => $entry, 'type' => null, 'required' => false];
                }

                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            $path = $entry['path'] ?? null;
            if (!is_string($path) || $path === '') {
                continue;
            }
            $type = $entry['type'] ?? null;
            if ($type !== null && (!is_string($type) || !in_array($type, Ref::TYPES, true))) {
                throw new LogicException(sprintf(
                    'onlineconf.map: %s declares an unknown type %s; allowed: %s',
                    $key,
                    json_encode($type),
                    implode(', ', Ref::TYPES),
                ));
            }
            $entries[$key] = [
                'path' => $path,
                'type' => $type,
                'required' => (bool) ($entry['required'] ?? false),
            ];
        }

        return $entries;
    }
}
