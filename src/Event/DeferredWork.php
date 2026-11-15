<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Event;

use Closure;

/**
 * Work held by {@see AfterCommitEventDispatcher::afterCommit()} like an event (1.19): run once the write is committed,
 * dropped with a rollback. Never dispatched to the application's listeners.
 *
 * @internal not covered by the backward compatibility promise
 */
final readonly class DeferredWork
{
    /**
     * @param Closure(): void $work the work (a log line)
     */
    public function __construct(
        public Closure $work,
    ) {
    }
}
