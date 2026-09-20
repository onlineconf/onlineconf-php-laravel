<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests\Support;

use Onlineconf\Laravel\MissingValue;

final class RecordingHandler
{
    /** @var list<MissingValue> */
    public static array $missing = [];

    public function __invoke(MissingValue $missing): void
    {
        self::$missing[] = $missing;
    }
}
