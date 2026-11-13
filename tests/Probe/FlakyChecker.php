<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Probe;

use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;

/**
 * Audit probe fixture: a checker that says yes twice and no afterwards, to see whether an inconsistent host decision
 * can be hammered into a reveal.
 */
class FlakyChecker implements InstanceSettingsAccessCheckerInterface
{
    /** How often the host checker was asked. */
    public int $calls = 0;

    /**
     * Whether the user may open the admin UI or one area.
     */
    public function canAccess(?object $user, ?string $area = null): bool
    {
        return ++$this->calls <= 2;
    }
}
