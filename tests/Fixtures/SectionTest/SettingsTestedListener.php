<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest;

use Nowo\InstanceSettingsBundle\Event\SettingsTestedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * 1.18: collects the SettingsTestedEvent records, as a host audit trail would.
 */
#[AsEventListener]
class SettingsTestedListener
{
    /** @var list<SettingsTestedEvent> */
    public array $events = [];

    public function __invoke(SettingsTestedEvent $event): void
    {
        $this->events[] = $event;
    }
}
