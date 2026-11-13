<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Security;

/**
 * Decides who may use the instance settings admin UI (REQ-UI-002). Set `security.access_checker` to a host service id
 * implementing this interface to replace the role-based default.
 */
interface InstanceSettingsAccessCheckerInterface
{
    /** Area id of the import / export page (used as `$area` and in `security.area_roles`). */
    public const AREA_PORTABILITY = '_portability';

    /**
     * Area id of the secret reveal endpoint (1.15): asked on top of the section's own area, so revealing a stored
     * secret needs both. With the role-based default checker its roles are `security.reveal_roles`
     * (`ROLE_DEVELOPER` unless the host says otherwise), never `access_roles`.
     */
    public const AREA_REVEAL = '_reveal';

    /**
     * Whether the user may open the admin UI at all (`$area` null) or one area: a settings section name,
     * {@see self::AREA_PORTABILITY} or {@see self::AREA_REVEAL}.
     *
     * @param object|null $user authenticated user of the main request firewall (null = anonymous)
     */
    public function canAccess(?object $user, ?string $area = null): bool;
}
