<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\EventListener;

use Nowo\InstanceSettingsBundle\Event\AfterCommitEventDispatcher;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Clears the settings memo and the bundle's private entity manager at every unit-of-work boundary (REQ-RUNTIME-002).
 *
 * `kernel.reset` alone is not enough: it does not run where the services resetter does not, i.e. a host that removes
 * or bypasses `services_resetter` (or drives `http_kernel` directly), a Messenger consumer started with `--no-reset`,
 * or a long-running console command. Without this listener such a consumer kept the rows it read for its first message
 * (for example an SMS provider token) until the process exited, and never saw a change saved by another process.
 *
 * Boundaries: the main `kernel.request` and `kernel.terminate`; Messenger `WorkerMessageReceived` / `Handled` /
 * `Failed` (names only: symfony/messenger stays optional); `console.command` and `console.terminate`. The services are
 * injected as `IGNORE_ON_UNINITIALIZED_REFERENCE`, so a boundary never builds them: only services already used by the
 * process are reset. End-of-unit boundaries run after {@see AfterCommitEventDispatcher} (priority 1024) has dispatched
 * the committed events.
 */
final readonly class WorkerBoundaryResetListener
{
    /** Priority of the start-of-unit boundaries (before anything reads a setting). */
    public const START_PRIORITY = 2048;

    /** Priority of the end-of-unit boundaries (after the committed events are dispatched). */
    public const END_PRIORITY = -1024;

    /** Messenger worker events (by name; the component is optional). */
    public const MESSENGER_RECEIVED = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent';

    public const MESSENGER_HANDLED = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent';

    public const MESSENGER_FAILED = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent';

    /** Start-of-unit events handled by {@see onBoundary()} (the main request goes through {@see onKernelRequest()}). */
    public const START_EVENTS = [self::MESSENGER_RECEIVED, ConsoleEvents::COMMAND];

    /** End-of-unit events handled by {@see onBoundary()}. */
    public const END_EVENTS = [KernelEvents::TERMINATE, self::MESSENGER_HANDLED, self::MESSENGER_FAILED, ConsoleEvents::TERMINATE];

    /**
     * @param iterable<ResetInterface> $resettables the settings memo and the private entity manager, when initialized
     */
    public function __construct(
        private iterable $resettables,
    ) {
    }

    /**
     * Main request start; sub-requests share the main request's unit of work.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->onBoundary();
        }
    }

    /**
     * Any other boundary: drops the memoised rows / image metadata and the private manager (recreated on next use).
     */
    public function onBoundary(): void
    {
        foreach ($this->resettables as $resettable) {
            $resettable->reset();
        }
    }
}
