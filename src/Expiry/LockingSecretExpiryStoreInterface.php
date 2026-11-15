<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;

/**
 * Optional capability of a {@see SecretExpiryStoreInterface} (1.19.1, audit QQ-D2-L-1): the dates of a section read with a
 * locking read inside a unit of work, so a save under the section lock decides on the dates as last committed, also
 * inside a REPEATABLE READ transaction of the host that read the table before. {@see InstanceSettings} uses it when the
 * aliased store implements it (the Doctrine one does); a custom store without it keeps the plain read.
 */
interface LockingSecretExpiryStoreInterface
{
    /**
     * Stored dates of one section, keyed by field name, read with a locking read (inside a unit of work).
     *
     * @return array<string, SecretExpiryRecord>
     */
    public function findBySectionForUpdate(string $section): array;
}
