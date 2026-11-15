<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Database\Support;

use Closure;

use function preg_match;

/**
 * State of {@see StatementHook} (1.19), one shared service of the kernel: DoctrineBundle builds one middleware instance per
 * connection, so the test arms this service instead.
 */
final class HookState
{
    private ?string $pattern = null;

    /** @var (Closure(): void)|null */
    private ?Closure $peer = null;

    public int $fired = 0;

    /**
     * Runs `$peer` before the next statement matching `$pattern`.
     *
     * @param Closure(): void $peer
     */
    public function arm(string $pattern, Closure $peer): void
    {
        $this->pattern = $pattern;
        $this->peer    = $peer;
        $this->fired   = 0;
    }

    /**
     * Runs the armed peer once, before a statement matching the pattern.
     */
    public function fire(string $sql): void
    {
        if ($this->pattern === null || !$this->peer instanceof Closure || preg_match($this->pattern, $sql) !== 1) {
            return;
        }
        $peer          = $this->peer;
        $this->pattern = null;
        $this->peer    = null;
        ++$this->fired;
        $peer();
    }
}
