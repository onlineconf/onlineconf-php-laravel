<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests\Support;

/**
 * Static and plain callables for the transform tests: what an application writes in config/*.php.
 */
final class Csv
{
    /** @var int how many times {@see countedSplit()} ran; a closure's by-reference use would not survive serialization */
    public static int $calls = 0;

    /**
     * @return list<string>
     */
    public static function split(?string $value): array
    {
        return $value === null || $value === '' ? [] : array_map('trim', explode(',', $value));
    }

    /**
     * @return list<string>
     */
    public static function countedSplit(?string $value): array
    {
        self::$calls++;

        return self::split($value);
    }

    public function __invoke(mixed $value): mixed
    {
        return $value;
    }
}
