<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Log\LogManager;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Onlineconf\Laravel\ConfigOverride;
use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Module;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function testADisabledClientLogsNothing(): void
    {
        $this->config()->set('logging.channels.probe', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        $this->config()->set('onlineconf.log_channel', 'probe');
        $this->writeModule(['/app/port' => 'snot-a-number']);
        $this->disabledClient();

        $module = $this->application()->make(Module::class);
        assert($module instanceof Module);
        self::assertSame(1, $module->getInt('/app/port', 1));
        self::assertSame('disabled', $module->version());

        self::assertSame([], $this->probeHandler()->getRecords(), 'not even the unreadable client config is logged');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function configuredOff(): iterable
    {
        yield '"false"' => ['false'];
        yield 'false, as env() reads "false"' => [false];
        yield '"0"' => ['0'];
        yield '0' => [0];
    }

    /**
     * Switched off by the configuration alone, as a config:cache'd process is, with nothing in the environment:
     * the client config is not read either, and a configured "required" does not switch it back on.
     */
    #[DataProvider('configuredOff')]
    public function testAClientSwitchedOffByTheConfigurationAloneLogsNothing(mixed $enabled): void
    {
        $this->config()->set('logging.channels.probe', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        $this->config()->set('onlineconf.log_channel', 'probe');
        $this->config()->set('onlineconf.enabled', $enabled);
        $this->config()->set('onlineconf.required', 'true');

        $module = $this->application()->make(Module::class);
        assert($module instanceof Module);

        self::assertSame('disabled', $module->version());
        self::assertSame([], $this->probeHandler()->getRecords(), 'the unreadable client config is not logged');
    }

    public function testAConfiguredTrueWinsOverTheEnvironment(): void
    {
        $this->useModule(['/app/name' => 'sdemo']);
        self::setEnabled('false');
        $this->config()->set('onlineconf.enabled', 'true');

        $module = $this->application()->make(Module::class);
        assert($module instanceof Module);

        self::assertSame('demo', $module->getString('/app/name', ''), 'as a cached configuration wins over .env');
    }

    /** Channels the counting driver has built; see {@see countingChannel()} */
    public static int $built = 0;

    /**
     * A "probe" channel whose driver counts the times it is built.
     */
    private function countingChannel(): void
    {
        self::$built = 0;
        $this->config()->set('logging.channels.probe', [
            'driver' => 'custom',
            'via' => static function (): Logger {
                LoggingTest::$built++;

                return new Logger('probe', [new TestHandler()]);
            },
        ]);
        $this->config()->set('onlineconf.log_channel', 'probe');
    }

    /**
     * The channel is built at the first record, not while the configuration loads: built then, it would miss the
     * application's facades and logging setup and fall back to Laravel's emergency logger (storage/logs), and a
     * client that writes nothing — a disabled one — would build it for nothing.
     */
    public function testTheChannelIsBuiltAtTheFirstRecordOnly(): void
    {
        $this->countingChannel();
        $this->useModule(['/app/port' => 'snot-a-number']);
        $this->config()->set('app.port', Onlineconf::getRefInt('/app/port', 1));

        ConfigOverride::install($this->application());
        $module = $this->application()->make(Module::class);
        assert($module instanceof Module);
        self::assertSame(0, self::$built, 'the override and the module manager hold the logger without building it');

        self::assertSame(1, $module->getInt('/app/port', 1));
        self::assertSame(1, self::$built);
        self::assertTrue($this->probeHandler()->hasWarningThatContains('/app/port'));
    }

    public function testADisabledClientBuildsNoChannel(): void
    {
        $this->countingChannel();
        $this->useModule(['/app/port' => 'snot-a-number']);
        $this->disabledClient();
        $this->config()->set('app.port', Onlineconf::getRefInt('/app/port', 1));

        ConfigOverride::install($this->application());
        $module = $this->application()->make(Module::class);
        assert($module instanceof Module);

        self::assertSame(1, config('app.port'));
        self::assertSame(1, $module->getInt('/app/port', 1));
        self::assertSame(0, self::$built);
    }
}
