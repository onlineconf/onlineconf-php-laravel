<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests;

use Laravel\SerializableClosure\SerializableClosure;
use Onlineconf\Laravel\Tests\Support\Csv;
use Onlineconf\Laravel\Tests\Support\RecordingErrorHandler;
use Onlineconf\Laravel\Tests\Support\Wakeup;
use Onlineconf\Laravel\Transform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

final class TransformTest extends PHPUnitTestCase
{
    protected function tearDown(): void
    {
        SerializableClosure::setSecretKey(null);
    }

    public function testAClosureIsStoredSerializedAndComesBackCallable(): void
    {
        $suffix = '!';
        $encoded = Transform::encode('/p', static fn (string $value): string => $value . $suffix);

        self::assertIsArray($encoded);
        self::assertArrayHasKey('closure', $encoded);
        $restored = eval('return ' . var_export($encoded, true) . ';');
        self::assertTrue(Transform::isEncoded($restored), 'var_export() keeps it, as config:cache needs');
        self::assertSame('a!', Transform::decode($restored, 'app.key', '/p')('a'), 'use variables are kept');
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function signers(): array
    {
        return [
            'no signer when stored, one when read' => [null, 'base64:read-key'],
            'a signer when stored, none when read' => ['base64:store-key', null],
            'two different signers' => ['base64:old-key', 'base64:rotated-key'],
        ];
    }

    #[DataProvider('signers')]
    public function testAStoredClosureDoesNotDependOnLaravelsSigner(?string $whenStored, ?string $whenRead): void
    {
        SerializableClosure::setSecretKey($whenStored);
        $encoded = Transform::encode('/p', static fn (string $value): string => strtoupper($value));

        SerializableClosure::setSecretKey($whenRead);

        self::assertIsArray($encoded);
        self::assertSame('A', Transform::decode($encoded, 'app.key', '/p')('a'), 'the stored closure is unsigned');
    }

    public function testFunctionNamesAndStaticMethodsAreStoredAsTheyAre(): void
    {
        self::assertSame('strtoupper', Transform::encode('/p', 'strtoupper'));
        self::assertSame([Csv::class, 'split'], Transform::encode('/p', [Csv::class, 'split']));
        self::assertSame(Csv::class . '::split', Transform::encode('/p', Csv::class . '::split'));

        self::assertSame('A', Transform::decode('strtoupper', 'app.key', '/p')('a'));
        self::assertSame(['a', 'b'], Transform::decode([Csv::class, 'split'], 'app.key', '/p')('a, b'));
    }

    public function testNoTransformIsNull(): void
    {
        self::assertNull(Transform::encode('/p', null));
    }

    public function testAFirstClassCallableOfAnInternalFunctionIsRejectedWithTheStringForm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("/my/path: trim(...) cannot be stored for config:cache; write 'trim' instead");

        Transform::encode('/my/path', trim(...));
    }

    public function testAFirstClassCallableOfAUserStaticMethodSurvivesTheRoundTrip(): void
    {
        $encoded = Transform::encode('/p', Csv::split(...));

        self::assertIsArray($encoded);
        $restored = eval('return ' . var_export($encoded, true) . ';');
        self::assertTrue(Transform::isEncoded($restored));
        self::assertSame(['a', 'b'], Transform::decode($restored, 'app.hosts', '/p')('a, b'));
    }

    public function testAFirstClassCallableOfAUserFunctionIsRejectedWithTheStringForm(): void
    {
        require_once __DIR__ . '/Support/functions.php';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            '/my/path: Onlineconf\\Laravel\\Tests\\Support\\split_hosts(...) cannot be stored for config:cache;'
            . " write 'Onlineconf\\Laravel\\Tests\\Support\\split_hosts' instead",
        );

        Transform::encode('/my/path', \Onlineconf\Laravel\Tests\Support\split_hosts(...));
    }

    public function testAStoredClosureWithACorruptTailNamesTheKeyAndPath(): void
    {
        $encoded = Transform::encode('/p', static fn (string $value): string => $value);
        self::assertIsArray($encoded);
        self::assertArrayHasKey('closure', $encoded);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('app.key (/p): the stored closure is not a serialized closure');

        Transform::decode(['closure' => substr($encoded['closure'], 0, -20)], 'app.key', '/p');
    }

    public function testMessagesWithoutAConfigKeyNameThePath(): void
    {
        try {
            Transform::apply(static function (): never {
                throw new \DomainException('boom');
            }, null, null, '/p');
            self::fail('the transform throws');
        } catch (\RuntimeException $e) {
            self::assertSame('OnlineConf transform of /p failed: boom', $e->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('/p: "no_such_function_in_this_codebase" is not callable');
        Transform::decode('no_such_function_in_this_codebase', null, '/p');
    }

    public function testAClosureWhoseSourceIsDeprecatedStillDecodesAndThePreviousHandlerSeesIt(): void
    {
        // Optional before required is deprecated at compile time since PHP 8.0: SerializableClosure compiles the
        // source again inside unserialize(), and that deprecation is not unserialize() complaining.
        $file = sys_get_temp_dir() . '/onlineconf-deprecated-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($file, "<?php\nreturn static fn (string \$prefix = 'x', string \$value): string => \$prefix . \$value;\n");
        $compiling = new RecordingErrorHandler();
        $decoding = new RecordingErrorHandler();
        set_error_handler($compiling);
        try {
            $closure = require $file;
            self::assertInstanceOf(\Closure::class, $closure);
            $encoded = Transform::encode('/p', $closure);
            self::assertIsArray($encoded);
            set_error_handler($decoding);
            try {
                $decoded = Transform::decode($encoded, 'app.key', '/p');
            } finally {
                restore_error_handler();
            }
        } finally {
            restore_error_handler();
            unlink($file);
        }

        self::assertSame('ab', $decoded('a', 'b'));
        self::assertCount(1, $decoding->errors, 'the deprecation reached the handler that was there before');
        self::assertSame(E_DEPRECATED, $decoding->errors[0][0]);
        self::assertStringContainsString('Optional parameter', $decoding->errors[0][1]);
    }

    public function testAPreviousHandlerThatReturnsNothingStillHandlesTheError(): void
    {
        $wakeup = new Wakeup('warn');
        $encoded = Transform::encode('/p', static fn (string $name): string => $wakeup->greet($name));
        self::assertIsArray($encoded);
        // Laravel's HandleExceptions::handleError() returns nothing for what it only logs.
        $handler = new RecordingErrorHandler(returnNothing: true);
        set_error_handler($handler);
        error_clear_last();
        try {
            Transform::decode($encoded, 'app.key', '/p');
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $handler->errors);
        self::assertNull(error_get_last(), "PHP's own handler did not run as well");
    }

    public function testAWarningDuringUnserializeThatIsNotUnserializesOwnGoesToThePreviousHandler(): void
    {
        $wakeup = new Wakeup('warn');
        $encoded = Transform::encode('/p', static fn (string $name): string => $wakeup->greet($name));
        self::assertIsArray($encoded);
        $seen = [];
        set_error_handler(static function (int $level, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });
        try {
            $decoded = Transform::decode($encoded, 'app.key', '/p');
        } finally {
            restore_error_handler();
        }

        self::assertSame('warn you', $decoded('you'));
        self::assertCount(1, $seen);
        self::assertStringStartsWith('hex2bin()', $seen[0], 'passed on, not turned into "not a serialized closure"');
    }

    public function testAnExceptionDuringUnserializeNamesTheKeyAndPath(): void
    {
        $wakeup = new Wakeup('throw');
        $encoded = Transform::encode('/p', static fn (string $name): string => $wakeup->greet($name));
        self::assertIsArray($encoded);

        try {
            Transform::decode($encoded, 'app.key', '/p');
            self::fail('the stored closure cannot be restored');
        } catch (\LogicException $e) {
            self::assertSame('app.key (/p): the stored closure could not be restored: cannot wake up', $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testAFirstClassCallableOfAnInternalStaticMethodIsRejectedWithThePairForm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("/my/path: DateTime::createFromFormat(...) cannot be stored for config:cache; write [DateTime::class, 'createFromFormat'] instead");

        Transform::encode('/my/path', \DateTime::createFromFormat(...));
    }

    public function testAFirstClassCallableOfAnObjectMethodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            '/my/path: ' . Wakeup::class . '->greet(...) cannot be stored for config:cache;'
            . " use a static method as [Class::class, 'method']",
        );

        Transform::encode('/my/path', (new Wakeup('x'))->greet(...));
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

    public function testAStoredNameThatIsNotCallableNamesTheKeyAndPath(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('app.key (/p): "no_such_function_in_this_codebase" is not callable');

        Transform::decode('no_such_function_in_this_codebase', 'app.key', '/p');
    }

    public function testAStoredClosureThatIsNotOneNamesTheKeyAndPath(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('app.key (/p): the stored closure is not a serialized closure');

        Transform::decode(['closure' => 'garbage'], 'app.key', '/p');
    }

    public function testAStoredClosureOfAnotherClassNamesTheKeyAndPath(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('app.key (/p): the stored closure is not a serialized closure');

        Transform::decode(['closure' => serialize(new SerializableClosure(static fn (): int => 1))], 'app.key', '/p');
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
