<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function array_key_exists;

/**
 * Role-based default checker: `security.access_roles` for the UI, `security.area_roles.<area>` (when set) for one
 * section or the import / export page. An empty role list denies (fail closed).
 */
final readonly class ConfigurableInstanceSettingsAccessChecker implements InstanceSettingsAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles roles that open the UI (at least one must be granted)
     * @param array<string, list<string>> $areaRoles area => roles replacing `access_roles` for that area
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
        private array $areaRoles = [],
    ) {
    }

    /**
     * Grants when the user holds any of the applicable roles; anonymous and empty role lists are denied.
     */
    public function canAccess(?object $user, ?string $area = null): bool
    {
        if ($user === null) {
            return false;
        }

        $roles = $area !== null && array_key_exists($area, $this->areaRoles) ? $this->areaRoles[$area] : $this->accessRoles;
        foreach ($roles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
