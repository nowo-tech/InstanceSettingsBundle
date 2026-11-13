<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Probe;

use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;

/**
 * Audit probe fixture: the UI gate and the reveal area granted, a section's own area never.
 */
class RevealOnlyChecker implements InstanceSettingsAccessCheckerInterface
{
    /**
     * Whether the user may open the admin UI or one area.
     */
    public function canAccess(?object $user, ?string $area = null): bool
    {
        return $area === null || $area === InstanceSettingsAccessCheckerInterface::AREA_REVEAL;
    }
}
