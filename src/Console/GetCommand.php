<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Console;

use Illuminate\Console\Command;
use Onlineconf\Exception\FormatException;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\OnlineconfException;
use Onlineconf\Laravel\ModuleManager;
use Onlineconf\Module;

final class GetCommand extends Command
{
    private const EXIT_NOT_FOUND = 1;
    private const EXIT_ERROR = 2;

    /** @var string */
    protected $signature = 'onlineconf:get
        {path : Key path, for example /my/service/db/host}
        {--json : Print the value as JSON (strings become JSON strings)}
        {--tree : Print getTree() of the path as pretty JSON}
        {--module= : Module name ("TREE") or file path; the default module when omitted}';

    protected $description = 'Read a value from OnlineConf through the application configuration';

    public function handle(ModuleManager $manager): int
    {
        $path = $this->argument('path');
        $path = is_string($path) ? $path : '';
        $moduleName = $this->option('module');

        try {
            $module = $manager->module(is_string($moduleName) && $moduleName !== '' ? $moduleName : null);
            $this->output->writeln($this->render($module, $path));
        } catch (NotFoundException) {
            return $this->fail("No such key: {$path}", self::EXIT_NOT_FOUND);
        } catch (OnlineconfException $e) {
            return $this->fail($e->getMessage(), self::EXIT_ERROR);
        }

        return self::SUCCESS;
    }

    /**
     * @throws OnlineconfException
     */
    private function render(Module $module, string $path): string
    {
        if ((bool) $this->option('tree')) {
            return self::json($module->getTree($path), JSON_PRETTY_PRINT);
        }
        if ((bool) $this->option('json')) {
            return self::json($module->require($path));
        }

        $raw = $module->getRaw($path);
        if ($raw === null) {
            throw new NotFoundException($path);
        }
        $type = substr($raw, 0, 1);
        if ($type !== 's' && $type !== 'j') {
            throw new FormatException(sprintf('%s: unknown value type %s', $path, json_encode($type, JSON_THROW_ON_ERROR)));
        }

        return substr($raw, 1);
    }

    private static function json(mixed $value, int $flags = 0): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | $flags);
    }

    private function fail(string $message, int $code): int
    {
        $this->output->getErrorStyle()->writeln($message);

        return $code;
    }
}
