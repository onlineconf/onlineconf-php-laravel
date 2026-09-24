<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Onlineconf\Exception\NotFoundException;
use Onlineconf\Exception\OpenException;
use Onlineconf\Laravel\Config\MapEntry;
use Onlineconf\Laravel\Config\OverridingRepository;
use Onlineconf\Laravel\Ref;
use Onlineconf\Module;
use Onlineconf\Source\ArraySource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

final class OverridingRepositoryTest extends PHPUnitTestCase
{
    /** @var array<string, mixed> */
    private const ITEMS = [
        'app' => [
            'name' => 'From config',
            'debug' => false,
            'workers' => 4,
            'ratio' => 0.5,
            'hosts' => ['a.example.com'],
            'secret' => null,
        ],
        'services' => [
            'mailer' => ['host' => 'mail.config', 'port' => 25],
            'other' => 'untouched',
        ],
    ];

    /** @var array<string, array{path: string, type: string}> */
    private const MAP = [
        'app.name' => ['path' => '/app/name', 'type' => Ref::TYPE_STRING],
        'app.debug' => ['path' => '/app/debug', 'type' => Ref::TYPE_BOOL],
        'app.workers' => ['path' => '/app/workers', 'type' => Ref::TYPE_INT],
        'app.ratio' => ['path' => '/app/ratio', 'type' => Ref::TYPE_FLOAT],
        'app.hosts' => ['path' => '/app/hosts', 'type' => Ref::TYPE_ARRAY],
        'app.secret' => ['path' => '/app/secret', 'type' => Ref::TYPE_RAW],
        'app.missing' => ['path' => '/app/missing', 'type' => Ref::TYPE_STRING],
        'services.mailer.host' => ['path' => '/services/mailer/host', 'type' => Ref::TYPE_STRING],
        'services.mailer.port' => ['path' => '/services/mailer/port', 'type' => Ref::TYPE_INT],
        'absent' => ['path' => '/absent', 'type' => Ref::TYPE_STRING],
    ];

    /** @var array<string, string|array<mixed>> */
    private const VALUES = [
        '/app/name' => 'From OnlineConf',
        '/app/debug' => '1',
        '/app/workers' => '8',
        '/app/ratio' => '0.25',
        '/app/hosts' => ['b.example.com', 'c.example.com'],
        '/app/secret' => 's3cret',
        '/services/mailer/host' => 'mail.onlineconf',
        // /services/mailer/port and /absent are deliberately missing
    ];

    private TestHandler $log;

    private Logger $logger;

    protected function setUp(): void
    {
        $this->log = new TestHandler();
        $this->logger = new Logger('test', [$this->log]);
    }

    /**
     * @param array<string, mixed>                                   $items
     * @param array<string, mixed>                                   $map
     * @param array<string, string|int|float|bool|array<mixed>|null> $values
     */
    private function repository(array $items = self::ITEMS, array $map = self::MAP, array $values = self::VALUES): OverridingRepository
    {
        $module = new Module(ArraySource::fromValues($values), $this->logger, 0);

        return new OverridingRepository($items, MapEntry::normalize($map), static fn (): Module => $module, $this->logger);
    }

    public function testMappedKeysAreReadWithTheDeclaredType(): void
    {
        $repository = $this->repository();

        self::assertSame('From OnlineConf', $repository->get('app.name'));
        self::assertTrue($repository->get('app.debug'));
        self::assertSame(8, $repository->get('app.workers'));
        self::assertSame(0.25, $repository->get('app.ratio'));
        self::assertSame(['b.example.com', 'c.example.com'], $repository->get('app.hosts'));
        self::assertSame('s3cret', $repository->get('app.secret'), 'raw: the value as stored');
        self::assertSame('mail.onlineconf', $repository->get('services.mailer.host'));
    }

    /**
     * type, the raw OnlineConf value, the fallback in config/*.php, the expected result.
     *
     * @return array<string, array{string, string|array<mixed>, mixed, mixed}>
     */
    public static function typedNodes(): array
    {
        return [
            'string' => [Ref::TYPE_STRING, 'text', 'dflt', 'text'],
            'int' => [Ref::TYPE_INT, '42', 1, 42],
            'float' => [Ref::TYPE_FLOAT, '0.25', 1.0, 0.25],
            'bool' => [Ref::TYPE_BOOL, '1', false, true],
            'duration' => [Ref::TYPE_DURATION, '1m', 1.0, 60.0],
            'duration_ms' => [Ref::TYPE_DURATION_MS, '1.5s', 1, 1500],
            'strings' => [Ref::TYPE_STRINGS, 'a, b', ['x'], ['a', 'b']],
            'array' => [Ref::TYPE_ARRAY, ['pool' => 5], [], ['pool' => 5]],
            'raw' => [Ref::TYPE_RAW, 'text', null, 'text'],
        ];
    }

    /**
     * @param string|array<mixed> $value
     */
    #[DataProvider('typedNodes')]
    public function testEveryTypeHasItsGetter(string $type, string|array $value, mixed $fallback, mixed $expected): void
    {
        $repository = $this->repository(['node' => $fallback], ['node' => ['path' => '/node', 'type' => $type]], ['/node' => $value]);

        self::assertSame($expected, $repository->get('node'));
    }

    /**
     * @param string|array<mixed> $value
     */
    #[DataProvider('typedNodes')]
    public function testEveryTypeHasItsRequireGetter(string $type, string|array $value, mixed $fallback, mixed $expected): void
    {
        $repository = $this->repository(
            ['node' => $fallback],
            ['node' => ['path' => '/node', 'type' => $type, 'required' => true]],
            ['/node' => $value],
        );

        self::assertSame($expected, $repository->get('node'));
    }

    public function testMissingNodeFallsBackSilently(): void
    {
        $repository = $this->repository();

        self::assertSame(25, $repository->get('services.mailer.port'));
        self::assertNull($repository->get('absent'));
        self::assertSame('dflt', $repository->get('absent', 'dflt'));
        self::assertSame([], $this->log->getRecords(), 'a node the tree does not have is the normal state');
    }

    public function testUnmappedKeysComeFromTheConfiguration(): void
    {
        $repository = $this->repository();

        self::assertSame('untouched', $repository->get('services.other'));
        self::assertSame('dflt', $repository->get('nothing', 'dflt'));
    }

    public function testUnparsableValueKeepsTheRealFallbackAndWarns(): void
    {
        $repository = $this->repository(values: ['/app/workers' => 'eight']);

        self::assertSame(4, $repository->get('app.workers'));
        self::assertTrue($this->log->hasWarningThatContains('/app/workers'));
    }

    public function testUnparsableValueFallsBackToNull(): void
    {
        $repository = $this->repository(
            ['node' => null],
            ['node' => ['path' => '/node', 'type' => Ref::TYPE_INT]],
            ['/node' => 'eight'],
        );

        self::assertNull($repository->get('node'), 'getRefInt(path, null): a null fallback is a null fallback');
        self::assertTrue($this->log->hasWarningThatContains('/node'));
    }

    public function testParentKeyIncludesMappedDescendants(): void
    {
        $repository = $this->repository();

        self::assertSame(
            ['mailer' => ['host' => 'mail.onlineconf', 'port' => 25], 'other' => 'untouched'],
            $repository->get('services'),
        );
        $app = $repository->get('app');
        self::assertIsArray($app);
        self::assertTrue($app['debug']);
        self::assertSame(8, $app['workers']);
        self::assertArrayNotHasKey('missing', $app, 'a descendant absent everywhere is not invented');
        self::assertArrayHasKey('secret', $app, 'a descendant the configuration has stays');
        self::assertSame('s3cret', $app['secret']);
    }

    public function testAllReturnsTheLoadedConfigurationWithoutSubstitution(): void
    {
        $all = $this->repository()->all();

        $app = $all['app'];
        self::assertIsArray($app);
        self::assertSame('From config', $app['name']);
        $services = $all['services'];
        self::assertIsArray($services);
        self::assertSame('untouched', $services['other']);
        $mailer = $services['mailer'];
        self::assertIsArray($mailer);
        self::assertSame('mail.config', $mailer['host']);
        self::assertArrayNotHasKey('absent', $all, 'config:cache must not freeze OnlineConf values');
    }

    public function testGetManyWithAndWithoutDefaults(): void
    {
        $repository = $this->repository();

        self::assertSame(
            ['app.name' => 'From OnlineConf', 'services.mailer.port' => 25, 'nothing' => 'dflt'],
            $repository->getMany(['app.name', 'services.mailer.port' => 100, 'nothing' => 'dflt']),
        );
        self::assertSame(
            ['app.name' => 'From OnlineConf', 'nothing' => 'dflt'],
            $repository->get(['app.name', 'nothing' => 'dflt']),
        );
        self::assertSame([], $repository->getMany([5]), 'a key that is not a string is skipped');
    }

    public function testHasConsultsOnlineconfForMappedKeys(): void
    {
        $repository = $this->repository();
        self::assertTrue($repository->has('app.name'));
        self::assertTrue($repository->has('services.other'));
        self::assertFalse($repository->has('absent'), 'neither in the configuration nor in OnlineConf');
        self::assertFalse($repository->has('nothing'));
        self::assertFalse($repository->has('app.missing'));
        self::assertNull($repository->get('app.missing'));

        $repository = $this->repository(values: self::VALUES + ['/absent' => 'present']);

        self::assertTrue($repository->has('absent'));
        self::assertSame('present', $repository->get('absent'));
    }

    public function testExplicitSetWinsOverTheMap(): void
    {
        $repository = $this->repository();

        $repository->set('app.name', 'Runtime');
        self::assertSame('Runtime', $repository->get('app.name'));

        $repository->set('services', ['mailer' => ['host' => 'runtime.host']]);
        self::assertSame('runtime.host', $repository->get('services.mailer.host'));
        self::assertSame(['mailer' => ['host' => 'runtime.host']], $repository->get('services'));

        $repository->set(['app.debug' => false]);
        self::assertFalse($repository->get('app.debug'));
        self::assertSame(8, $repository->get('app.workers'), 'other mapped keys stay mapped');
    }

    public function testPushReadsTheOverrideAndThenUnmaps(): void
    {
        $repository = $this->repository();

        $repository->push('app.hosts', 'd.example.com');

        self::assertSame(['b.example.com', 'c.example.com', 'd.example.com'], $repository->get('app.hosts'));
    }

    public function testArrayAccess(): void
    {
        $repository = $this->repository();

        self::assertSame('From OnlineConf', $repository['app.name']);
        self::assertTrue(isset($repository['app.name']));
        self::assertFalse(isset($repository['absent']));
    }

    public function testInvalidJsonFallsBackAndLogsAnError(): void
    {
        $module = new Module(new ArraySource(['/app/hosts' => 'j{not json']), $this->logger, 0);
        $repository = new OverridingRepository(self::ITEMS, MapEntry::normalize(self::MAP), static fn (): Module => $module, $this->logger);

        self::assertSame(['a.example.com'], $repository->get('app.hosts'));
        self::assertTrue($this->log->hasErrorThatContains('/app/hosts'));
    }

    public function testSetOnAChildUnmapsTheParent(): void
    {
        $items = ['services' => ['mailer' => ['host' => 'from-config']]];
        $map = [
            'services' => ['path' => '/services', 'type' => Ref::TYPE_ARRAY],
            'services.mailer.host' => ['path' => '/services/mailer/host', 'type' => Ref::TYPE_STRING],
        ];
        $values = [
            '/services' => ['mailer' => ['host' => 'from-onlineconf']],
            '/services/mailer/host' => 'from-onlineconf-leaf',
        ];
        $repository = $this->repository($items, $map, $values);

        $repository->set('services.mailer.host', 'runtime');

        self::assertSame('runtime', $repository->get('services.mailer.host'));
        self::assertSame(
            ['mailer' => ['host' => 'runtime']],
            $repository->get('services'),
            'services is unmapped too, so it comes straight from the configuration',
        );
    }

    public function testAMissingModuleIsNotedOnceAndNeverRetried(): void
    {
        $attempts = 0;
        $factory = static function () use (&$attempts): Module {
            $attempts++;

            throw new OpenException('cannot open TREE.cdb');
        };
        $repository = new OverridingRepository(self::ITEMS, MapEntry::normalize(self::MAP), $factory, $this->logger);

        self::assertSame('From config', $repository->get('app.name'));
        self::assertSame('From config', $repository->get('app.name'));
        self::assertSame(4, $repository->get('app.workers'));
        self::assertFalse($repository->has('absent'));

        self::assertSame(1, $attempts, 'a machine without a module pays one failed open per process');
        self::assertCount(1, $this->log->getRecords());
        self::assertTrue($this->log->hasDebugThatContains('cannot open TREE.cdb'), 'a missing module is not an error');
    }

    public function testRequiredNodeThrowsWhenOnlineconfDoesNotHaveIt(): void
    {
        $repository = $this->repository(
            ['node' => 'dflt'],
            ['node' => ['path' => '/node', 'type' => Ref::TYPE_STRING, 'required' => true]],
            [],
        );

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('/node');
        $repository->get('node');
    }

    public function testRequiredNodeFallsBackWhenThereIsNoModule(): void
    {
        $repository = new OverridingRepository(
            ['node' => 'dflt'],
            MapEntry::normalize(['node' => ['path' => '/node', 'type' => Ref::TYPE_STRING, 'required' => true]]),
            static fn (): Module => throw new OpenException('gone'),
            $this->logger,
        );

        self::assertSame('dflt', $repository->get('node'), 'no module at all is a different thing from an empty node');
    }

    public function testMapExposesTheEntries(): void
    {
        $repository = $this->repository(
            ['node' => 1],
            ['node' => ['path' => '/node', 'type' => Ref::TYPE_INT, 'required' => true]],
        );

        self::assertSame(['node' => ['path' => '/node', 'type' => Ref::TYPE_INT, 'required' => true]], $repository->map());
    }
}
