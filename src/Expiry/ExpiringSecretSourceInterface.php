<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry;

use DateTimeImmutable;

/**
 * Read port of the expiring secrets (1.17): which declared expiring secret fields expire within a window. Framework
 * free (dates and {@see ExpiringSecret}s only), for a dashboard, a health check or a host-side reminder.
 *
 * Only fields that declare `expires: true`, hold a stored secret and have a stored date are listed. The secret value
 * never goes through this port.
 */
interface ExpiringSecretSourceInterface
{
    /**
     * Expiring secrets whose date is at most `$days` days after `$today` (already expired ones included), soonest
     * first. `$today` counts by its calendar date **in its own zone**.
     *
     * @param int $days window in days (0 = expiring today or expired)
     *
     * @return list<ExpiringSecret>
     */
    public function expiringWithin(int $days, DateTimeImmutable $today): array;
}
