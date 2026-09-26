<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests\Support;

/**
 * An error handler that records what reaches it. Returning null, as Laravel's HandleExceptions::handleError()
 * does for what it only logs, still counts as handled; returning true says so explicitly.
 */
final class RecordingErrorHandler
{
    /** @var list<array{int, string}> */
    public array $errors = [];

    public function __construct(private readonly bool $returnNothing = false)
    {
    }

    public function __invoke(int $level, string $message): mixed
    {
        $this->errors[] = [$level, $message];

        return $this->returnNothing ? null : true;
    }
}
