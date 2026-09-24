<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests\Support;

use Onlineconf\Source;

/**
 * A source whose only node disappears on the second update check: what onlineconf-updater does between the
 * has() and the read of one config() call when check_interval is 0.
 */
final class VanishingSource implements Source
{
    private int $checks = 0;

    private bool $gone = false;

    public function __construct(private readonly string $raw)
    {
    }

    public function getRaw(string $path): ?string
    {
        return $this->gone ? null : $this->raw;
    }

    public function reloadIfChanged(): bool
    {
        if (++$this->checks < 2) {
            return false;
        }
        $this->gone = true;

        return true;
    }

    public function version(): string
    {
        return $this->gone ? '2' : '1';
    }

    public function name(): string
    {
        return 'vanishing';
    }
}
