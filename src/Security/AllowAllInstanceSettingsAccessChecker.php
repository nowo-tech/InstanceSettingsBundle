<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Security;

/**
 * Grants everything: wired only with `security.allow_unauthenticated: true`, which the container accepts only when
 * `kernel.debug` is true (dev / demo).
 */
final class AllowAllInstanceSettingsAccessChecker implements InstanceSettingsAccessCheckerInterface
{
    /**
     * Always true.
     */
    public function canAccess(?object $user, ?string $area = null): bool
    {
        return true;
    }
}
