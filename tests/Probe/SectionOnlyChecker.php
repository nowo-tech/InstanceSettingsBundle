<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Probe;

use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;

/**
 * Audit probe fixture: every area granted except the reveal area.
 */
class SectionOnlyChecker implements InstanceSettingsAccessCheckerInterface
{
    /**
     * Whether the user may open the admin UI or one area.
     */
    public function canAccess(?object $user, ?string $area = null): bool
    {
        return $area !== InstanceSettingsAccessCheckerInterface::AREA_REVEAL;
    }
}
