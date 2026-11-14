<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Probe;

use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;

/**
 * Audit probe fixture: every area granted, the UI gate itself never — the reveal must still be refused.
 */
class NoUiGateChecker implements InstanceSettingsAccessCheckerInterface
{
    /**
     * Whether the user may open the admin UI or one area.
     */
    public function canAccess(?object $user, ?string $area = null): bool
    {
        return $area !== null;
    }
}
