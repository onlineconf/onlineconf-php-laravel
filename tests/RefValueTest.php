<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Facade;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Onlineconf\Exception\InvalidJsonException;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\OpenException;
use Onlineconf\Laravel\EagerReads;
use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Laravel\ImmediateModule;
use Onlineconf\Laravel\MarkerReader;
use Onlineconf\Laravel\Ref;
use Onlineconf\Laravel\Tests\Support\Csv;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ref::value(): a marker read on its own, through the facade's immediate read of its type.
 */
final class RefValueTest extends TestCase
{
    /** @var array<string, string> */
    private const MODULE = [
        '/app/name' => 'sFrom OnlineConf',
        '/app/workers' => 's8',
        '/app/broken' => 'seight',
        '/app/hosts' => 'sx, y',
        '/app/opts' => 'j{"pool":5}',
    ];

    private ?Application $bare = null;

    protected function setUp(): void
    {
        parent::setUp();
        EagerReads::flush();
        ImmediateModule::flush();
    }

    protected function tearDown(): void
    {
        putenv('ONLINECONF_DIR');
        EagerReads::flush();
        ImmediateModule::flush();
        if ($this->bare !== null) {
            $this->bare->flush();
            $this->bare = null;
        }
        Facade::setFacadeApplication($this->application());
        Container::setInstance($this->application());
        parent::tearDown();
    }

    private function probe(): TestHandler
    {
        $this->config()->set('logging.channels.probe', ['driver' => 'monolog', 'handler' => TestHandler::class]);
        $this->config()->set('onlineconf.log_channel', 'probe');
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

    /**
     * While config/*.php loads: nothing of the package is registered, the facade has no application.
     */
    private function duringConfigLoad(bool $withModule = true): void
    {
        if ($withModule) {
            $this->writeModule(self::MODULE);
        }
        putenv('ONLINECONF_DIR=' . $this->tempDir());
        $this->bare = new Application($this->tempDir());
        Facade::setFacadeApplication(null);
    }

    public function testAnOptionalMarkerReadsItsNodeWithTheDeclaredType(): void
    {
        $this->useModule(self::MODULE);

        self::assertSame(8, Onlineconf::getRefInt('/app/workers', 2)->value());
        self::assertSame('From OnlineConf', Onlineconf::getRefString('/app/name', null)->value(), 'a null fallback is a fallback');
        self::assertSame(['pool' => 5], Onlineconf::getRef('/app/opts', null)->value(), 'raw: decoded JSON');
    }

    public function testAnOptionalMarkerFallsBackWhenTheNodeIsAbsent(): void
    {
        $this->useModule(self::MODULE);

        self::assertSame(2, Onlineconf::getRefInt('/app/gone', 2)->value());
        self::assertNull(Onlineconf::getRefString('/app/gone', null)->value());
    }

    public function testAnUnparsableNodeWarnsAndFallsBack(): void
    {
        $this->useModule(self::MODULE);
        $log = $this->probe();

        self::assertSame(2, Onlineconf::getRefInt('/app/broken', 2)->value(), 'the client warns and returns the fallback');
        self::assertNull(Onlineconf::getRefInt('/app/broken', null)->value(), 'the same for a null fallback');

        self::assertCount(2, $log->getRecords());
        self::assertTrue($log->hasWarningThatContains('/app/broken'));
    }

    public function testWithoutAModuleAnOptionalMarkerFallsBackAndARequiredOneThrows(): void
    {
        $this->config()->set('onlineconf.dir', $this->tempDir());

        self::assertSame(2, Onlineconf::getRefInt('/app/workers', 2)->value());
        self::assertNull(Onlineconf::getRefInt('/app/workers', null)->value());

        $this->expectException(OpenException::class);
        Onlineconf::requireRefInt('/app/workers')->value();
    }

    public function testARequiredMarkerReadsItsNodeOrThrows(): void
    {
        $this->useModule(self::MODULE);

        self::assertSame(8, Onlineconf::requireRefInt('/app/workers')->value());
        self::assertSame(['pool' => 5], Onlineconf::requireRef('/app/opts')->value());

        $this->expectException(NotFoundException::class);
        Onlineconf::requireRefString('/app/gone')->value();
    }

    public function testTheTransformIsAppliedToTheNodeAndToTheFallback(): void
    {
        $this->useModule(self::MODULE);

        self::assertSame(['x', 'y'], Onlineconf::getRefString('/app/hosts', 'a, b', [Csv::class, 'split'])->value());
        self::assertSame(['a', 'b'], Onlineconf::getRefString('/app/gone', 'a, b', [Csv::class, 'split'])->value());
        self::assertSame(
            'port 8',
            Onlineconf::requireRefInt('/app/workers', static fn (int $workers): string => 'port ' . $workers)->value(),
            'a stored closure is decoded and applied',
        );
    }

    public function testAFailingTransformIsWrapped(): void
    {
        $this->useModule(self::MODULE);
        $ref = Onlineconf::getRefString('/app/name', null, static function (?string $value): never {
            throw new \DomainException('cannot use ' . $value);
        });

        try {
            $ref->value();
            self::fail('a failing transform is a programming error');
        } catch (\RuntimeException $e) {
            self::assertSame('OnlineConf transform of /app/name failed: cannot use From OnlineConf', $e->getMessage());
            self::assertInstanceOf(\DomainException::class, $e->getPrevious());
        }
    }

    public function testWhileTheConfigurationLoadsItIsAnImmediateRead(): void
    {
        $this->duringConfigLoad();

        self::assertSame('From OnlineConf', Onlineconf::getRefString('/app/name', 'dflt')->value());
        self::assertSame(8, Onlineconf::requireRefInt('/app/workers')->value());
        self::assertNull(Onlineconf::getRefInt('/app/broken', null)->value(), 'unparsable: the fallback, silently as any immediate read');

        $reads = EagerReads::all();
        self::assertCount(3, $reads, 'recorded like any immediate read');
        self::assertSame(['/app/name', Ref::TYPE_STRING, 'dflt'], [$reads[0]->path, $reads[0]->type, $reads[0]->default]);
        self::assertSame(['/app/workers', Ref::TYPE_INT, null], [$reads[1]->path, $reads[1]->type, $reads[1]->default]);
    }

    public function testWhileTheConfigurationLoadsWithoutAModule(): void
    {
        $this->duringConfigLoad(withModule: false);

        self::assertSame('dflt', Onlineconf::getRefString('/app/name', 'dflt')->value());
        self::assertNull(Onlineconf::getRefString('/app/name', null)->value());

        $this->expectException(OpenException::class);
        Onlineconf::requireRefString('/app/name')->value();
    }

    /**
     * type, the stored value, a fallback of that type, the expected value.
     *
     * @return array<string, array{string, string, mixed, mixed}>
     */
    public static function everyType(): array
    {
        return [
            'string' => [Ref::TYPE_STRING, 'stext', 'dflt', 'text'],
            'int' => [Ref::TYPE_INT, 's42', 1, 42],
            'float' => [Ref::TYPE_FLOAT, 's0.25', 1.0, 0.25],
            'bool' => [Ref::TYPE_BOOL, 's1', false, true],
            'duration' => [Ref::TYPE_DURATION, 's1m', 1.0, 60.0],
            'duration_ms' => [Ref::TYPE_DURATION_MS, 's1.5s', 1, 1500],
            'strings' => [Ref::TYPE_STRINGS, 'sa, b', ['x'], ['a', 'b']],
            'array' => [Ref::TYPE_ARRAY, 'j{"pool":5}', [], ['pool' => 5]],
            'raw' => [Ref::TYPE_RAW, 'stext', 'dflt', 'text'],
        ];
    }

    public function testTheTypeProviderCoversEveryType(): void
    {
        self::assertSame(Ref::TYPES, array_keys(self::everyType()));
    }

    #[DataProvider('everyType')]
    public function testEveryTypeReadsItsNode(string $type, string $stored, mixed $fallback, mixed $expected): void
    {
        $this->useModule(['/node' => $stored]);

        self::assertSame($expected, (new Ref('/node', $type, $fallback))->value(), 'with a fallback of the type');
        self::assertSame($expected, (new Ref('/node', $type, null))->value(), 'with a null fallback');
        self::assertSame($expected, (new Ref('/node', $type, null, true))->value(), 'required');
        self::assertSame($fallback, (new Ref('/gone', $type, $fallback))->value(), 'absent: the fallback');
    }

    public function testInvalidJsonLogsAnErrorAndFallsBackUnlessRequired(): void
    {
        $this->useModule(['/app/opts' => 'j{not json']);
        $log = $this->probe();

        self::assertSame(['pool' => 1], Onlineconf::getRefArray('/app/opts', ['pool' => 1])->value());
        self::assertNull(Onlineconf::getRefArray('/app/opts', null)->value());
        self::assertCount(2, $log->getRecords());
        self::assertTrue($log->hasErrorThatContains('/app/opts'), 'as config() does');

        $this->expectException(InvalidJsonException::class);
        Onlineconf::requireRefArray('/app/opts')->value();
    }

    public function testARequiredMarkerWithoutItsNodeThrowsWhileTheConfigurationLoads(): void
    {
        $this->duringConfigLoad();

        $this->expectException(NotFoundException::class);
        Onlineconf::requireRefString('/app/gone')->value();
    }

    public function testAnImmediateReadRecordsWhetherItWasRequired(): void
    {
        $this->duringConfigLoad();

        Onlineconf::getRefString('/app/name', 'dflt')->value();
        Onlineconf::requireRefInt('/app/workers')->value();
        Onlineconf::getRefString('/app/name', null)->value();

        $reads = EagerReads::all();
        self::assertCount(3, $reads, 'one record per read');
        self::assertFalse($reads[0]->required);
        self::assertTrue($reads[1]->required);
        self::assertFalse($reads[2]->required, 'a null fallback is still an optional marker');
        self::assertNull($reads[2]->default);
    }

    public function testAStoredClosureIsDecodedOncePerMarker(): void
    {
        $this->useModule(self::MODULE);
        $ref = Onlineconf::getRefString('/app/hosts', null, static fn (?string $hosts): array => Csv::split($hosts));

        self::assertSame(['x', 'y'], $ref->value());
        self::assertSame(MarkerReader::transform($ref), MarkerReader::transform($ref), 'the decoded closure is kept for the marker');
        self::assertSame(['x', 'y'], $ref->value());
    }
}
