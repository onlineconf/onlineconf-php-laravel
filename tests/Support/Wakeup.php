<?php

declare(strict_types=1);

namespace Onlineconf\Laravel\Tests\Support;

/**
 * A value captured by a closure that makes noise, or fails, while it is unserialized: what a stored closure's
 * use variables can do inside Transform::decode().
 */
final class Wakeup
{
    public function __construct(public readonly string $mode)
    {
    }

    public function __wakeup(): void
    {
        if ($this->mode === 'warn') {
            // An E_WARNING that has nothing to do with unserialize() itself.
            hex2bin('not hexadecimal');
        }
        if ($this->mode === 'throw') {
            throw new \RuntimeException('cannot wake up');
        }
    }

    public function greet(string $name): string
    {
        return $this->mode . ' ' . $name;
    }
}
