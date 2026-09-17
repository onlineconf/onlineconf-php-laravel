<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Log\LogManager;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Onlineconf\Module;

final class LoggingTest extends TestCase
{
    private function probeHandler(): TestHandler
    {
        $logManager = $this->application()->make(LogManager::class);
        assert($logManager instanceof LogManager);
        $channel = $logManager->channel('probe');
        assert($channel instanceof IlluminateLogger);
        $logger = $channel->getLogger();
        self::assertInstanceOf(Logger::class, $logger);
        $handler = $logger->getHandlers()[0] ?? null;
        self::assertInstanceOf(TestHandler::class, $handler);

        return $handler;
    }

    public function testConfiguredChannelReceivesClientWarnings(): void
    {
        $this->config()->set('logging.channels.probe', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        $this->config()->set('onlineconf.log_channel', 'probe');
        $this->useModule(['/app/port' => 'snot-a-number']);

        $module = $this->application()->make(Module::class);
        assert($module instanceof Module);
        self::assertSame(1, $module->getInt('/app/port', 1));

        self::assertTrue($this->probeHandler()->hasWarningThatContains('/app/port'));
    }

    public function testDefaultLoggerIsUsedWhenNoChannelIsConfigured(): void
    {
        $this->config()->set('logging.channels.probe', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        $this->config()->set('logging.default', 'probe');
        $this->useModule(['/app/port' => 'snot-a-number']);

        $module = $this->application()->make(Module::class);
        assert($module instanceof Module);
        self::assertSame(1, $module->getInt('/app/port', 1));

        self::assertTrue($this->probeHandler()->hasWarningThatContains('/app/port'));
    }
}
