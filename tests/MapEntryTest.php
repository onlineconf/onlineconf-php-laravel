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
            'app.name' => ['path' => '/app/name', 'type' => Ref::TYPE_STRING, 'required' => true],
            'app.port' => ['path' => '/app/port', 'type' => Ref::TYPE_INT, 'required' => false],
        ], $entries);
        self::assertSame($entries, MapEntry::normalize($entries), 'reading them back changes nothing');
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
        ]));
        self::assertSame([], MapEntry::normalize('not a map'));
        self::assertSame([], MapEntry::normalize(null));
    }
}
