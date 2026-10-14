<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * A logger resolved at its first record. The package takes its logger while the configuration loads, before the
 * application's facades and providers: a channel built then may not build at all — Laravel falls back to its
 * emergency logger, which writes to storage/logs — and a client that writes nothing, a disabled one, would build
 * it for nothing. Resolved later, the channel is the one the application configured.
 *
 * @internal
 */
final class LazyLogger implements LoggerInterface
{
    use LoggerTrait;

    private ?LoggerInterface $logger = null;

    /**
     * @param \Closure(): LoggerInterface $resolve
     */
    public function __construct(private readonly \Closure $resolve)
    {
    }

    /**
     * {@inheritDoc}
     *
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->logger ??= ($this->resolve)();
        $this->logger->log($level, $message, $context);
    }
}
