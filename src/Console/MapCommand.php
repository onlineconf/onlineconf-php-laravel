<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Onlineconf\Laravel\Config\MapEntry;
use Onlineconf\Laravel\EagerRead;
use Onlineconf\Laravel\EagerReads;

/**
 * Lists every OnlineConf node this application's configuration refers to: the map derived from the Ref
 * markers in config/*.php together with the explicit "onlineconf.map", and the nodes that were read while
 * the configuration was loading. The input for dumping values and for checking them against the tree.
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
        $fallbacks = $config->all();
        $reads = EagerReads::all();

        if ((bool) $this->option('json')) {
            $this->output->writeln($this->json($map, $fallbacks, $reads));

            return self::SUCCESS;
        }
        if ($map === [] && $reads === []) {
            $this->output->writeln('No OnlineConf nodes: no Ref marker in config/*.php and no entry in onlineconf.map.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($map as $key => $entry) {
            $rows[] = [
                $key,
                $entry['path'],
                $entry['type'] ?? 'by fallback',
                $entry['required'] ? 'yes' : 'no',
                self::printable(Arr::get($fallbacks, $key)),
            ];
        }
        $this->table(['Config key', 'Path', 'Type', 'Required', 'Fallback'], $rows);

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
     * @param array<string, array{path: string, type: string|null, required: bool}> $map
     * @param array<mixed>                                                          $fallbacks
     * @param list<EagerRead>                                                       $reads
     *
     * @throws \JsonException
     */
    private function json(array $map, array $fallbacks, array $reads): string
    {
        $nodes = [];
        foreach ($map as $key => $entry) {
            $nodes[$key] = $entry + ['fallback' => Arr::get($fallbacks, $key)];
        }

        return json_encode(
            [
                'map' => (object) $nodes,
                'eager' => array_map(static fn (EagerRead $read): array => [
                    'path' => $read->path,
                    'type' => $read->type,
                    'default' => $read->default,
                    'missing' => $read->missing,
                    'module' => $read->module,
                    'trace' => $read->trace,
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
