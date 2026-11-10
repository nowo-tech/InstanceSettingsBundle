<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;

/**
 * Read port of the stored settings as persistence-neutral rows (1.10, DDD P2): an application that reports on, audits
 * or migrates the stored configuration reads {@see StoredSettingRow}s instead of the Doctrine entity returned by
 * {@see SettingValueRepositoryInterface}.
 *
 * The bundle's default implementation maps the rows of whatever {@see SettingValueRepositoryInterface} is aliased.
 * Application code reading single settings keeps using {@see InstanceSettingsInterface}
 * (it applies defaults, overrides and type checks; these rows are the raw store).
 */
interface SettingRowReaderInterface
{
    /**
     * Every stored row (secrets decrypted), ordered by section and name.
     *
     * @return list<StoredSettingRow>
     */
    public function findAllRows(): array;

    /**
     * Rows of one section, ordered by name.
     *
     * @return list<StoredSettingRow>
     */
    public function findRowsBySection(string $section): array;
}
