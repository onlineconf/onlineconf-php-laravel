<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Onlineconf\Laravel\Tests\Support\Csv;
use Onlineconf\Laravel\Transform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

final class TransformTest extends PHPUnitTestCase
{
    public function testAClosureIsStoredSerializedAndComesBackCallable(): void
    {
        $suffix = '!';
        $encoded = Transform::encode('/p', static fn (string $value): string => $value . $suffix);

        self::assertIsArray($encoded);
        self::assertArrayHasKey('closure', $encoded);
        $restored = eval('return ' . var_export($encoded, true) . ';');
        self::assertTrue(Transform::isEncoded($restored), 'var_export() keeps it, as config:cache needs');
        self::assertSame('a!', Transform::decode($restored)('a'), 'use variables are kept');
    }

    public function testFunctionNamesAndStaticMethodsAreStoredAsTheyAre(): void
    {
        self::assertSame('strtoupper', Transform::encode('/p', 'strtoupper'));
        self::assertSame([Csv::class, 'split'], Transform::encode('/p', [Csv::class, 'split']));
        self::assertSame(Csv::class . '::split', Transform::encode('/p', Csv::class . '::split'));

        self::assertSame('A', Transform::decode('strtoupper')('a'));
        self::assertSame(['a', 'b'], Transform::decode([Csv::class, 'split'])('a, b'));
    }

    public function testNoTransformIsNull(): void
    {
        self::assertNull(Transform::encode('/p', null));
    }

    public function testAnInvokableObjectIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The transform of the OnlineConf marker for /my/path');

        Transform::encode('/my/path', new Csv());
    }

    public function testAnObjectMethodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/my/path');

        Transform::encode('/my/path', [new Csv(), 'split']);
    }

    public function testAStoredNameThatIsNotCallableFails(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no_such_function_in_this_codebase');

        Transform::decode('no_such_function_in_this_codebase');
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function storedForms(): array
    {
        return [
            'function name' => ['trim', true],
            'static method pair' => [[Csv::class, 'split'], true],
            'closure' => [['closure' => 'O:...'], true],
            'null' => [null, false],
            'int' => [5, false],
            'closure that is not a string' => [['closure' => 5], false],
            'three elements' => [['a', 'b', 'c'], false],
            'keyed pair' => [['x' => 'a', 'y' => 'b'], false],
        ];
    }

    #[DataProvider('storedForms')]
    public function testWhatIsAndIsNotAStoredForm(mixed $value, bool $stored): void
    {
        self::assertSame($stored, Transform::isEncoded($value));
    }
}
