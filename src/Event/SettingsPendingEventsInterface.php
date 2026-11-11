<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Event;

/**
 * Committed-but-not-yet-dispatched {@see SettingsChangedEvent}s (1.11.1, public API, SemVer).
 *
 * A save inside the application's transaction holds its event until the outermost commit; the event is then
 * dispatched at the next point where DBAL reports no open transaction (never from inside the commit): the next
 * settings event, a Doctrine `postFlush`, or the end of the request, console command or Messenger message. Call
 * {@see dispatchPending()} right after your commit to get them at once.
 */
interface SettingsPendingEventsInterface
{
    /**
     * Dispatches the committed events when the bundle's connection has no open transaction.
     *
     * @return int Events dispatched (0 inside a transaction or when nothing is pending)
     */
    public function dispatchPending(): int;

    /**
     * Events held by open transactions plus committed events not dispatched yet.
     */
    public function countPending(): int;
}
