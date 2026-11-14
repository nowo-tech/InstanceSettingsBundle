<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Event\SecretRevealedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * 1.15: collects the audit records of the reveal endpoint, as a host audit trail would.
 */
#[AsEventListener]
class RevealAuditListener
{
    /** @var list<SecretRevealedEvent> */
    public array $events = [];

    public function __invoke(SecretRevealedEvent $event): void
    {
        $this->events[] = $event;
    }
}
