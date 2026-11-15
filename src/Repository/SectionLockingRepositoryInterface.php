<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;

/**
 * Optional capability of a {@see SettingValueRepositoryInterface} (1.19, Beacon spec 239): the writers of one section are
 * serialized, and read the rows they change as committed by the writer before them. {@see InstanceSettings}, the seeders,
 * the import and the row writer use it when the aliased repository implements it (the Doctrine one does); a custom
 * repository without it keeps the 1.18 behaviour.
 *
 * Both methods are called inside {@see SettingValueRepositoryInterface::transactional()}; the lock is held until the
 * outermost transaction ends. Lock order: sections in ascending name order, then their expiry dates and files.
 */
interface SectionLockingRepositoryInterface
{
    /**
     * Takes the write lock of `$section` in the open transaction.
     */
    public function lockSection(string $section): void;

    /**
     * Rows of one section as last committed (a locking read, never an older snapshot of the transaction), ordered by name;
     * the section name is re-checked byte for byte.
     *
     * @return list<SettingValue>
     */
    public function findBySectionForUpdate(string $section): array;
}
