<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use DateTimeImmutable;

/**
 * Atomic primitives of the section test state (1.18, audit PU-L2 / L3): an insert that fails when the (section, name)
 * row exists, and an update / delete that apply only to the row as it was read (same id and time). Everything that
 * decides something — the built-in rate window, the running marker — is built on them, so concurrent requests can
 * neither both win nor undo each other's rows.
 *
 * @internal not covered by the backward compatibility promise
 */
interface SectionTestStateStorageInterface
{
    /**
     * Every row of a reserved section.
     *
     * @return list<SectionTestStateRow>
     */
    public function rows(string $section): array;

    /**
     * Inserts a row; null when a row of that section and name already exists (another request won).
     */
    public function insert(string $section, string $name, string $value, DateTimeImmutable $at): ?SectionTestStateRow;

    /**
     * Replaces the value of `$row` only if it is unchanged since it was read; false otherwise.
     */
    public function update(SectionTestStateRow $row, string $value, DateTimeImmutable $at): bool;

    /**
     * Deletes `$row` only if it is unchanged since it was read; false otherwise.
     */
    public function delete(SectionTestStateRow $row): bool;
}
