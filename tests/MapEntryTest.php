<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Onlineconf\Laravel\Config\MapEntry;
use Onlineconf\Laravel\Ref;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

final class MapEntryTest extends PHPUnitTestCase
{
    public function testOldFormatKeepsWorking(): void
    {
        self::assertSame(
            ['app.name' => ['path' => '/app/name', 'type' => null, 'required' => false]],
            MapEntry::normalize(['app.name' => '/app/name']),
        );
    }

    public function testNewFormatIsTakenAsIsAndIsIdempotent(): void
    {
        $entries = MapEntry::normalize([
            'app.name' => ['path' => '/app/name', 'type' => Ref::TYPE_STRING, 'required' => true],
            'app.port' => ['path' => '/app/port'],
        ]);

        self::assertSame([
            'app.name' => ['path' => '/app/name', 'type' => Ref::TYPE_STRING, 'required' => true],
            'app.port' => ['path' => '/app/port', 'type' => null, 'required' => false],
        ], $entries);
        self::assertSame($entries, MapEntry::normalize($entries), 'a normalised map normalises to itself');
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('onlineconf.map: app.name declares an unknown type "nonsense"');

        MapEntry::normalize(['app.name' => ['path' => '/app/name', 'type' => 'nonsense']]);
    }

    public function testTheAllowedTypesAreNamedInTheMessage(): void
    {
        try {
            MapEntry::normalize(['app.name' => ['path' => '/app/name', 'type' => 5]]);
            self::fail('a type that is not a type must be rejected');
        } catch (\LogicException $e) {
            self::assertStringContainsString('duration_ms', $e->getMessage());
            self::assertStringContainsString('app.name', $e->getMessage());
        }
    }

    public function testJunkIsDropped(): void
    {
        self::assertSame([], MapEntry::normalize([
            0 => '/numeric/key',
            '' => '/empty/key',
            'a' => 5,
            'b' => '',
            'c' => [],
            'd' => ['path' => ''],
            'e' => ['path' => 42],
        ]));
        self::assertSame([], MapEntry::normalize('not a map'));
        self::assertSame([], MapEntry::normalize(null));
    }
}
