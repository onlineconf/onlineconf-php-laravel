<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Testing;

use Onlineconf\Laravel\Facades\Onlineconf;
use Onlineconf\Source\ArraySource;

/**
 * For test cases that prefer a method over the facade; identical to {@see Onlineconf::fake()}.
 */
trait InteractsWithOnlineconf
{
    /**
     * @param array<string, string|int|float|bool|array<mixed>|null> $values path → PHP value
     */
    protected function fakeOnlineconf(array $values = [], ?string $name = null): ArraySource
    {
        return Onlineconf::fake($values, $name);
    }
}
