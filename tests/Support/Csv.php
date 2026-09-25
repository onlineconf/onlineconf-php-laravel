<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests\Support;

/**
 * Static and plain callables for the transform tests: what an application writes in config/*.php.
 */
final class Csv
{
    /**
     * @return list<string>
     */
    public static function split(?string $value): array
    {
        return $value === null || $value === '' ? [] : array_map('trim', explode(',', $value));
    }

    public function __invoke(mixed $value): mixed
    {
        return $value;
    }
}
