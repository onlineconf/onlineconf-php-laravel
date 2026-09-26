<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests\Support;

/**
 * A plain user function, for the first-class callable tests.
 *
 * @return list<string>
 */
function split_hosts(?string $value): array
{
    return Csv::split($value);
}
