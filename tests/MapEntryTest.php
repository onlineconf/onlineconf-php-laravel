<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Onlineconf\Laravel\Config\MapEntry;
use Onlineconf\Laravel\Ref;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

final class MapEntryTest extends PHPUnitTestCase
{
    public function testDerivedEntriesAreReadBackAsTheyWereWritten(): void
    {
        $entries = MapEntry::normalize([
            'app.name' => ['path' => '/app/name', 'type' => Ref::TYPE_STRING, 'required' => true],
            'app.port' => ['path' => '/app/port', 'type' => Ref::TYPE_INT],
        ]);

        self::assertSame([
            'app.name' => ['path' => '/app/name', 'type' => Ref::TYPE_STRING, 'required' => true, 'fallback' => null, 'transform' => null],
            'app.port' => ['path' => '/app/port', 'type' => Ref::TYPE_INT, 'required' => false, 'fallback' => null, 'transform' => null],
        ], $entries);
        self::assertSame($entries, MapEntry::normalize($entries), 'reading them back changes nothing');
    }

    public function testTheRawFallbackAndTheStoredTransformAreKept(): void
    {
        self::assertSame(
            ['hosts' => ['path' => '/hosts', 'type' => Ref::TYPE_STRING, 'required' => false, 'fallback' => 'a,b', 'transform' => 'trim']],
            MapEntry::normalize(['hosts' => ['path' => '/hosts', 'type' => Ref::TYPE_STRING, 'fallback' => 'a,b', 'transform' => 'trim']]),
        );
    }

    public function testAnEntryWrittenBy12TakesItsFallbackFromTheConfiguration(): void
    {
        $items = ['app' => ['name' => 'From config', 'secret' => null]];

        self::assertSame(
            [
                'app.name' => ['path' => '/app/name', 'type' => Ref::TYPE_STRING, 'required' => false, 'fallback' => 'From config', 'transform' => null],
                'app.secret' => ['path' => '/app/secret', 'type' => Ref::TYPE_STRING, 'required' => true, 'fallback' => null, 'transform' => null],
                'app.shaped' => ['path' => '/app/shaped', 'type' => Ref::TYPE_STRING, 'required' => false, 'fallback' => null, 'transform' => null],
            ],
            MapEntry::normalize(
                [
                    'app.name' => ['path' => '/app/name', 'type' => Ref::TYPE_STRING, 'required' => false],
                    'app.secret' => ['path' => '/app/secret', 'type' => Ref::TYPE_STRING, 'required' => true],
                    'app.shaped' => ['path' => '/app/shaped', 'type' => Ref::TYPE_STRING, 'fallback' => null],
                ],
                $items,
            ),
            'a 1.2 cache has no fallback in its entries; an explicit null stays null',
        );
    }

    public function testAnythingThatIsNotAnEntryIsDropped(): void
    {
        self::assertSame([], MapEntry::normalize([
            0 => ['path' => '/numeric/key', 'type' => 'string'],
            '' => ['path' => '/empty/key', 'type' => 'string'],
            'a' => '/app/old-format',
            'b' => ['type' => 'string'],
            'c' => ['path' => '', 'type' => 'string'],
            'd' => ['path' => '/d'],
            'e' => ['path' => '/e', 'type' => 'nonsense'],
            'f' => ['path' => '/f', 'type' => 5],
            'g' => ['path' => '/g', 'type' => 'string', 'transform' => 5],
        ]));
        self::assertSame([], MapEntry::normalize('not a map'));
        self::assertSame([], MapEntry::normalize(null));
    }
}
