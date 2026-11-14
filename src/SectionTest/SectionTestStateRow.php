<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use DateTimeImmutable;

/**
 * One row of the section test state (1.18): a last report, a running marker or a rate window slot, as read from the
 * store. Its id and time identify the row as it was read, so a conditional update or delete
 * ({@see SectionTestStateStorageInterface}) applies only while nobody changed it since (audit PU-L2 / L3).
 *
 * @internal not covered by the backward compatibility promise
 */
final readonly class SectionTestStateRow
{
    /**
     * @param int $id row id
     * @param string $section reserved section ({@see SectionTestStore::REPORTS}, LOCKS, RATES)
     * @param string $name row name (a hash)
     * @param string|null $value stored text (report JSON, marker token, window JSON)
     * @param DateTimeImmutable $updatedAt last write
     */
    public function __construct(
        public int $id,
        public string $section,
        public string $name,
        public ?string $value,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
