<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Config;

use Onlineconf\Laravel\Ref;

/**
 * Normalisation of the "onlineconf.map" value into the entries {@see OverridingRepository} reads:
 * "key => path" (the 1.1 format, the type follows the fallback) and
 * "key => ['path' => ..., 'type' => ..., 'required' => ...]" (what a {@see Ref} marker produces).
 *
 * Anything that is not a usable entry is dropped, so a typo in the published config file cannot break boot.
 */
final class MapEntry
{
    /**
     * @return array<string, array{path: string, type: string|null, required: bool}>
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
            $entries[$key] = [
                'path' => $path,
                'type' => is_string($type) && in_array($type, Ref::TYPES, true) ? $type : null,
                'required' => (bool) ($entry['required'] ?? false),
            ];
        }

        return $entries;
    }
}
