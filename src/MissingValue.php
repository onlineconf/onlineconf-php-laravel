<?php

declare(strict_types=1);

namespace Onlineconf\Laravel;

/**
 * What the "on_missing" handler receives when config() reads a mapped key that OnlineConf does not have:
 * everything needed to find the config() call and the OnlineConf path to create.
 */
final class MissingValue
{
    /** @var string|null file of the first frame outside vendor/ and this package, null when there is none */
    public readonly ?string $file;

    public readonly ?int $line;

    /**
     * @param string       $configKey the Laravel configuration key, e.g. "mail.mailers.smtp.host"
     * @param string       $path      the mapped OnlineConf path, e.g. "/my/mail/smtp/host"
     * @param mixed        $fallback  the value config() returns instead (from config/*.php)
     * @param string       $module    name of the module that lacks the key
     * @param list<string> $trace     up to five "file:line" frames outside vendor/ and this package, innermost first
     */
    public function __construct(
        public readonly string $configKey,
        public readonly string $path,
        public readonly mixed $fallback,
        public readonly string $module,
        public readonly array $trace,
    ) {
        $first = $trace[0] ?? null;
        if ($first !== null && preg_match('/^(.+):(\d+)$/', $first, $matches) === 1) {
            $this->file = $matches[1];
            $this->line = (int) $matches[2];
        } else {
            $this->file = null;
            $this->line = null;
        }
    }
}
