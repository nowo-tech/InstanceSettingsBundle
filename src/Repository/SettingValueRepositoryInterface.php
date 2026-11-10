<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use Nowo\InstanceSettingsBundle\Entity\SettingValue;

/**
 * Storage of the instance setting rows.
 *
 * The bundle's own storage SPI. Its row methods are typed with the Doctrine entity {@see SettingValue}: since 1.11
 * (architecture bar rule 3) application code reads and writes the raw store through the persistence-neutral ports
 * {@see SettingRowReaderInterface} and {@see SettingRowWriterInterface} instead. The entity-typed methods are deprecated
 * as a public API and change to DTOs in 2.0; they keep working, and the bundle still calls them internally in 1.x.
 */
interface SettingValueRepositoryInterface
{
    /**
     * Every stored row, in one query (secrets decrypted).
     *
     * @return list<SettingValue>
     *
     * @deprecated since 1.11 as a public API, use {@see SettingRowReaderInterface::findAllRows()}; typed with DTOs in 2.0
     */
    public function findAll(): array;

    /**
     * Rows of one section, in one query.
     *
     * @return list<SettingValue>
     *
     * @deprecated since 1.11 as a public API, use {@see SettingRowReaderInterface::findRowsBySection()}; typed with DTOs in 2.0
     */
    public function findBySection(string $section): array;

    /**
     * Persists new / changed rows, removes the given ones and flushes once.
     *
     * @param list<SettingValue> $save
     * @param list<SettingValue> $remove
     *
     * @deprecated since 1.11 as a public API, use {@see SettingRowWriterInterface::writeRows()}; typed with DTOs in 2.0
     */
    public function apply(array $save, array $remove): void;

    /**
     * Runs a callback inside one database transaction (all-or-nothing imports).
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function transactional(callable $callback): mixed;
}
