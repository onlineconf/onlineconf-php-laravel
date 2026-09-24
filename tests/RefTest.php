<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Laravel\Ref;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

final class RefTest extends PHPUnitTestCase
{
    public function testEveryFacadeConstructorBuildsATypedMarker(): void
    {
        self::assertRef(Onlineconf::refString('/a/string', 'dflt'), '/a/string', Ref::TYPE_STRING, 'dflt');
        self::assertRef(Onlineconf::refInt('/a/int', 10), '/a/int', Ref::TYPE_INT, 10);
        self::assertRef(Onlineconf::refFloat('/a/float', 0.5), '/a/float', Ref::TYPE_FLOAT, 0.5);
        self::assertRef(Onlineconf::refBool('/a/bool', true), '/a/bool', Ref::TYPE_BOOL, true);
        self::assertRef(Onlineconf::refDuration('/a/dur', 30.0), '/a/dur', Ref::TYPE_DURATION, 30.0);
        self::assertRef(Onlineconf::refDurationMs('/a/ms', 5000), '/a/ms', Ref::TYPE_DURATION_MS, 5000);
        self::assertRef(Onlineconf::refStrings('/a/list', ['a', 'b']), '/a/list', Ref::TYPE_STRINGS, ['a', 'b']);
        self::assertRef(Onlineconf::refArray('/a/opts', ['pool' => 5]), '/a/opts', Ref::TYPE_ARRAY, ['pool' => 5]);
        self::assertRef(Onlineconf::ref('/a/raw', 'x'), '/a/raw', Ref::TYPE_RAW, 'x');
    }

    public function testOmittedFallbackMakesTheNodeRequired(): void
    {
        foreach (
            [
                Onlineconf::refString('/a'),
                Onlineconf::refInt('/a'),
                Onlineconf::refFloat('/a'),
                Onlineconf::refBool('/a'),
                Onlineconf::refDuration('/a'),
                Onlineconf::refDurationMs('/a'),
                Onlineconf::refStrings('/a'),
                Onlineconf::refArray('/a'),
                Onlineconf::ref('/a'),
            ] as $ref
        ) {
            self::assertTrue($ref->required, $ref->type . ': an omitted fallback means the node must exist');
            self::assertNull($ref->fallback);
        }
    }

    public function testExplicitNullFallbackIsNotRequired(): void
    {
        foreach (
            [
                Onlineconf::refString('/a', null),
                Onlineconf::refInt('/a', null),
                Onlineconf::refFloat('/a', null),
                Onlineconf::refBool('/a', null),
                Onlineconf::refDuration('/a', null),
                Onlineconf::refDurationMs('/a', null),
                Onlineconf::refStrings('/a', null),
                Onlineconf::refArray('/a', null),
                Onlineconf::ref('/a', null),
            ] as $ref
        ) {
            self::assertFalse($ref->required, $ref->type . ': an explicit null fallback is a fallback');
            self::assertNull($ref->fallback);
        }
    }

    public function testVarExportRoundTrip(): void
    {
        $ref = Onlineconf::refArray('/a/opts', ['pool' => 5]);

        $exported = var_export($ref, true);
        $restored = eval('return ' . $exported . ';');

        self::assertInstanceOf(Ref::class, $restored);
        self::assertRef($restored, '/a/opts', Ref::TYPE_ARRAY, ['pool' => 5]);

        $required = eval('return ' . var_export(Onlineconf::refString('/a/secret'), true) . ';');
        self::assertInstanceOf(Ref::class, $required);
        self::assertTrue($required->required);
    }

    public function testSetStateFallsBackForAnIncompleteState(): void
    {
        $ref = Ref::__set_state([]);

        self::assertSame('', $ref->path);
        self::assertSame(Ref::TYPE_RAW, $ref->type);
        self::assertNull($ref->fallback);
        self::assertFalse($ref->required);
    }

    private static function assertRef(Ref $ref, string $path, string $type, mixed $fallback): void
    {
        self::assertSame($path, $ref->path);
        self::assertSame($type, $ref->type);
        self::assertSame($fallback, $ref->fallback);
        self::assertFalse($ref->required);
    }
}
