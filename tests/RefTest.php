<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Laravel\Ref;
use Onlineconf\Laravel\Tests\Support\Csv;
use Onlineconf\Laravel\Transform;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

final class RefTest extends PHPUnitTestCase
{
    public function testEveryFacadeConstructorBuildsATypedMarker(): void
    {
        self::assertRef(Onlineconf::getRefString('/a/string', 'dflt'), '/a/string', Ref::TYPE_STRING, 'dflt');
        self::assertRef(Onlineconf::getRefInt('/a/int', 10), '/a/int', Ref::TYPE_INT, 10);
        self::assertRef(Onlineconf::getRefFloat('/a/float', 0.5), '/a/float', Ref::TYPE_FLOAT, 0.5);
        self::assertRef(Onlineconf::getRefBool('/a/bool', true), '/a/bool', Ref::TYPE_BOOL, true);
        self::assertRef(Onlineconf::getRefDuration('/a/dur', 30.0), '/a/dur', Ref::TYPE_DURATION, 30.0);
        self::assertRef(Onlineconf::getRefDurationMs('/a/ms', 5000), '/a/ms', Ref::TYPE_DURATION_MS, 5000);
        self::assertRef(Onlineconf::getRefStrings('/a/list', ['a', 'b']), '/a/list', Ref::TYPE_STRINGS, ['a', 'b']);
        self::assertRef(Onlineconf::getRefArray('/a/opts', ['pool' => 5]), '/a/opts', Ref::TYPE_ARRAY, ['pool' => 5]);
        self::assertRef(Onlineconf::getRef('/a/raw', 'x'), '/a/raw', Ref::TYPE_RAW, 'x');
    }

    public function testEveryRequiredConstructorMarksTheNode(): void
    {
        $required = [
            Ref::TYPE_STRING => Onlineconf::requireRefString('/a'),
            Ref::TYPE_INT => Onlineconf::requireRefInt('/a'),
            Ref::TYPE_FLOAT => Onlineconf::requireRefFloat('/a'),
            Ref::TYPE_BOOL => Onlineconf::requireRefBool('/a'),
            Ref::TYPE_DURATION => Onlineconf::requireRefDuration('/a'),
            Ref::TYPE_DURATION_MS => Onlineconf::requireRefDurationMs('/a'),
            Ref::TYPE_STRINGS => Onlineconf::requireRefStrings('/a'),
            Ref::TYPE_ARRAY => Onlineconf::requireRefArray('/a'),
            Ref::TYPE_RAW => Onlineconf::requireRef('/a'),
        ];

        foreach ($required as $type => $ref) {
            self::assertSame('/a', $ref->path);
            self::assertSame($type, $ref->type, 'requireRef* declares the same type as getRef*');
            self::assertTrue($ref->required);
            self::assertNull($ref->fallback);
        }
    }

    public function testANullFallbackIsAFallback(): void
    {
        foreach (
            [
                Onlineconf::getRefString('/a', null),
                Onlineconf::getRefInt('/a', null),
                Onlineconf::getRefFloat('/a', null),
                Onlineconf::getRefBool('/a', null),
                Onlineconf::getRefDuration('/a', null),
                Onlineconf::getRefDurationMs('/a', null),
                Onlineconf::getRefStrings('/a', null),
                Onlineconf::getRefArray('/a', null),
                Onlineconf::getRef('/a', null),
            ] as $ref
        ) {
            self::assertFalse($ref->required, $ref->type . ': getRef* never requires the node');
            self::assertNull($ref->fallback);
        }
    }

    public function testVarExportRoundTrip(): void
    {
        $ref = Onlineconf::getRefArray('/a/opts', ['pool' => 5]);

        $exported = var_export($ref, true);
        $restored = eval('return ' . $exported . ';');

        self::assertInstanceOf(Ref::class, $restored);
        self::assertRef($restored, '/a/opts', Ref::TYPE_ARRAY, ['pool' => 5]);

        $required = eval('return ' . var_export(Onlineconf::requireRefString('/a/secret'), true) . ';');
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

    public function testUsingAMarkerAsAValueFails(): void
    {
        $ref = Onlineconf::getRefBool('/a/debug', false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Onlineconf::getRef*() marker for /a/debug used as a value');
        // A cast is exactly the case getRef*() cannot serve: (bool) on any object is true, (int) is 1.
        self::assertNotSame('', (string) $ref);
    }

    public function testEveryConstructorTakesATransform(): void
    {
        $refs = [
            Onlineconf::getRefString('/a', 'x', 'strtoupper'),
            Onlineconf::getRefInt('/a', 1, 'strtoupper'),
            Onlineconf::getRefFloat('/a', 1.0, 'strtoupper'),
            Onlineconf::getRefBool('/a', true, 'strtoupper'),
            Onlineconf::getRefDuration('/a', 1.0, 'strtoupper'),
            Onlineconf::getRefDurationMs('/a', 1, 'strtoupper'),
            Onlineconf::getRefStrings('/a', [], 'strtoupper'),
            Onlineconf::getRefArray('/a', [], 'strtoupper'),
            Onlineconf::getRef('/a', null, 'strtoupper'),
            Onlineconf::requireRefString('/a', 'strtoupper'),
            Onlineconf::requireRefInt('/a', 'strtoupper'),
            Onlineconf::requireRefFloat('/a', 'strtoupper'),
            Onlineconf::requireRefBool('/a', 'strtoupper'),
            Onlineconf::requireRefDuration('/a', 'strtoupper'),
            Onlineconf::requireRefDurationMs('/a', 'strtoupper'),
            Onlineconf::requireRefStrings('/a', 'strtoupper'),
            Onlineconf::requireRefArray('/a', 'strtoupper'),
            Onlineconf::requireRef('/a', 'strtoupper'),
        ];

        foreach ($refs as $ref) {
            self::assertSame('strtoupper', $ref->transform, $ref->type);
        }
        self::assertNull(Onlineconf::getRefString('/a', 'x')->transform, 'no transform unless one is given');
    }

    public function testAClosureTransformSurvivesVarExport(): void
    {
        $ref = Onlineconf::getRefString('/a', 'a,b', static fn (?string $value): array => explode(',', (string) $value));

        $restored = eval('return ' . var_export($ref, true) . ';');

        self::assertInstanceOf(Ref::class, $restored);
        self::assertSame($ref->transform, $restored->transform);
        self::assertIsArray($restored->transform);
        self::assertSame(['a', 'b'], Transform::decode($restored->transform, 'app.hosts', '/a')('a,b'));
    }

    public function testATransformThatCannotBeCachedIsRejectedAtTheMarker(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/my/hosts');

        Onlineconf::getRefString('/my/hosts', null, new Csv());
    }

    public function testSetStateDropsAnUnusableTransform(): void
    {
        self::assertNull(Ref::__set_state(['path' => '/a', 'transform' => 5])->transform);
    }
}
