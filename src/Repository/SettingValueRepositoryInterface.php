<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use Nowo\InstanceSettingsBundle\Entity\SettingValue;

/**
 * Storage of the instance setting rows.
 */
interface SettingValueRepositoryInterface
{
    /**
     * Every stored row, in one query (secrets decrypted).
     *
     * @return list<SettingValue>
     */
    public function findAll(): array;

    /**
     * Rows of one section, in one query.
     *
     * @return list<SettingValue>
     */
    public function findBySection(string $section): array;

    /**
     * Persists new / changed rows, removes the given ones and flushes once.
     *
     * @param list<SettingValue> $save
     * @param list<SettingValue> $remove
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
