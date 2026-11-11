<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;

/**
 * Write port of the raw settings store with persistence-neutral rows (1.11, architecture bar rule 3): the counterpart
 * of {@see SettingRowReaderInterface} for migrations, restores and tooling that must not build the Doctrine entity
 * returned by {@see SettingValueRepositoryInterface}.
 *
 * It writes the store as given: no section schema, normalization or `required` checks and no
 * {@see SettingsChangedEvent}. Application code changing settings keeps using
 * {@see InstanceSettingsInterface::save()}.
 */
interface SettingRowWriterInterface
{
    /**
     * Writes the rows in one transaction: each row inserts or replaces the stored row of its section and name (a
     * secret row stores {@see StoredSettingRow::secret()}, encrypted at rest; a plain row its value), and each
     * `section.field` key of `$remove` deletes that row (a missing one is ignored).
     *
     * @param list<StoredSettingRow> $save rows to insert or replace
     * @param list<string> $remove `section.field` keys of the rows to delete
     *
     * @throws InvalidArgumentException for a secret row without its secret (unserialized or undecryptable) or a malformed key
     */
    public function writeRows(array $save, array $remove = []): void;
}
