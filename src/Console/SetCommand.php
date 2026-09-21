<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Console;

use Illuminate\Console\Command;
use Onlineconf\Cdb\CdbReader;
use Onlineconf\Cdb\CdbWriter;
use Onlineconf\Cdb\ConfWriter;
use Onlineconf\Exception\OpenException;
use Onlineconf\Exception\WriteException;
use Onlineconf\Laravel\ModuleManager;
use Onlineconf\Source\ArraySource;

/**
 * Edits a local module file. CDB cannot be changed in place, so the file is read whole, changed, the child
 * lists are regenerated and both the .cdb and the human-readable .conf next to it are written again.
 */
final class SetCommand extends Command
{
    private const EXIT_NOT_FOUND = 1;
    private const EXIT_ERROR = 2;

    /** @var string */
    protected $signature = 'onlineconf:set
        {path : Key path, for example /my/service/db/host}
        {value? : The new value; not needed when deleting}
        {--json : Store the value as JSON (type "j"); it must be valid JSON text}
        {--delete : Remove the key}
        {--module= : Module name ("TREE") or file path; the default module when omitted}';

    protected $description = 'Set or delete a key in a local OnlineConf module file (rebuilds the .cdb and the .conf next to it)';

    public function handle(ModuleManager $manager): int
    {
        $path = $this->argument('path');
        $path = is_string($path) ? $path : '';
        $value = $this->argument('value');
        $delete = (bool) $this->option('delete');
        $moduleName = $this->option('module');
        $settings = $manager->settings();
        $file = $settings->fileName(is_string($moduleName) && $moduleName !== '' ? $moduleName : $settings->module);

        if (!$delete && !is_string($value)) {
            return $this->reportError('A value is required unless --delete is given', self::EXIT_ERROR);
        }
        if ($path === '' || str_ends_with($path, '/')) {
            return $this->reportError('Child lists ("<path>/") are generated from the keys; give a key path', self::EXIT_ERROR);
        }
        if (!str_starts_with($path, '/')) {
            return $this->reportError('Key path must start with "/"', self::EXIT_ERROR);
        }
        if (!is_writable(dirname($file))) {
            return $this->reportError(sprintf('%s: directory is not writable', dirname($file)), self::EXIT_ERROR);
        }

        try {
            $raw = CdbReader::read($file);
        } catch (OpenException $e) {
            return $this->reportError($e->getMessage(), self::EXIT_ERROR);
        }
        $raw = array_filter($raw, static fn (int|string $key): bool => !str_ends_with((string) $key, '/'), ARRAY_FILTER_USE_KEY);

        if ($delete) {
            if (!array_key_exists($path, $raw)) {
                return $this->reportError("No such key: {$path}", self::EXIT_NOT_FOUND);
            }
            unset($raw[$path]);
        } elseif ((bool) $this->option('json')) {
            try {
                json_decode((string) $value, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return $this->reportError('Invalid JSON: ' . $e->getMessage(), self::EXIT_ERROR);
            }
            $raw[$path] = 'j' . $value;
        } else {
            $raw[$path] = 's' . $value;
        }

        $raw = ArraySource::withChildLists($raw);
        try {
            CdbWriter::write($file, $raw);
            $base = self::withoutExtension($file);
            ConfWriter::write($base . '.conf', basename($base), $raw);
        } catch (WriteException $e) {
            return $this->reportError($e->getMessage(), self::EXIT_ERROR);
        }
        $this->output->writeln(sprintf('%s: %s %s', $file, $delete ? 'deleted' : 'set', $path));

        return self::SUCCESS;
    }

    /**
     * "/dir/TREE.cdb" → "/dir/TREE"; a file without extension stays as is.
     */
    private static function withoutExtension(string $file): string
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        return $extension === '' ? $file : substr($file, 0, -strlen($extension) - 1);
    }

    private function reportError(string $message, int $code): int
    {
        $this->output->getErrorStyle()->writeln($message);

        return $code;
    }
}
