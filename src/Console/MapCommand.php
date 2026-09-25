<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Onlineconf\Laravel\Config\MapEntry;
use Onlineconf\Laravel\EagerRead;
use Onlineconf\Laravel\EagerReads;

/**
 * Lists every OnlineConf node this application's configuration refers to: the map derived from the Ref
 * markers in config/*.php and the nodes that were read while the configuration was loading. The input for
 * dumping values and for checking them against the tree. Fallbacks are shown as written in config/*.php,
 * before any transform.
 *
 * @phpstan-import-type Entry from MapEntry
 */
final class MapCommand extends Command
{
    /** @var string */
    protected $signature = 'onlineconf:map
        {--json : Print the map and the reads as JSON}';

    protected $description = 'List the OnlineConf nodes the configuration refers to';

    public function handle(Repository $config): int
    {
        $map = MapEntry::normalize($config->get('onlineconf.map'));
        $reads = EagerReads::all();

        if ((bool) $this->option('json')) {
            $this->output->writeln($this->json($map, $reads));

            return self::SUCCESS;
        }
        if ($map === [] && $reads === []) {
            $this->output->writeln('No OnlineConf nodes: config/*.php declares no marker and reads none.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($map as $key => $entry) {
            $rows[] = [
                $key,
                $entry['path'],
                $entry['type'],
                $entry['required'] ? 'yes' : 'no',
                $entry['transform'] !== null ? 'yes' : 'no',
                self::printable($entry['fallback']),
            ];
        }
        $this->table(['Config key', 'Path', 'Type', 'Required', 'Transform', 'Fallback'], $rows);

        if ($reads !== []) {
            $this->output->writeln('Read while the configuration was loading:');
            $this->table(
                ['Path', 'Type', 'Default'],
                array_map(static fn (EagerRead $read): array => [$read->path, $read->type, self::printable($read->default)], $reads),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, Entry> $map
     * @param list<EagerRead>      $reads
     *
     * @throws \JsonException
     */
    private function json(array $map, array $reads): string
    {
        $nodes = [];
        foreach ($map as $key => $entry) {
            $nodes[$key] = [
                'path' => $entry['path'],
                'type' => $entry['type'],
                'required' => $entry['required'],
                'fallback' => $entry['fallback'],
                'transform' => $entry['transform'] !== null,
            ];
        }

        return json_encode(
            [
                'map' => (object) $nodes,
                'eager' => array_map(static fn (EagerRead $read): array => [
                    'path' => $read->path,
                    'type' => $read->type,
                    'default' => $read->default,
                ], $reads),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
    }

    /**
     * The fallback as it is: this is a local tool, and seeing the value is the point.
     */
    private static function printable(mixed $value): string
    {
        return is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
