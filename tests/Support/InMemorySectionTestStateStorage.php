<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestStateRow;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestStateStorageInterface;

use function in_array;

/**
 * 1.18 (audit PU-L2 / L3): an in-memory section test state table with the database's guarantees — (section, name) is
 * unique, and a conditional update / delete applies only to the row as it was read (id + time). freeze() makes every
 * read return the rows as they were at that moment, as N concurrent requests that all read before any of them wrote.
 */
final class InMemorySectionTestStateStorage implements SectionTestStateStorageInterface
{
    /** @var array<string, SectionTestStateRow> section NUL name => row */
    public array $rows = [];

    /** @var array<string, SectionTestStateRow>|null */
    private ?array $snapshot = null;

    private int $nextId = 1;

    /**
     * @param list<string> $failInserts sections whose inserts lose against a concurrent request
     */
    public function __construct(
        public array $failInserts = [],
    ) {
    }

    public function freeze(): void
    {
        $this->snapshot = $this->rows;
    }

    public function thaw(): void
    {
        $this->snapshot = null;
    }

    public function rows(string $section): array
    {
        $out = [];
        foreach ($this->snapshot ?? $this->rows as $row) {
            if ($row->section === $section) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function insert(string $section, string $name, string $value, DateTimeImmutable $at): ?SectionTestStateRow
    {
        $key = $section . "\0" . $name;
        if (isset($this->rows[$key]) || in_array($section, $this->failInserts, true)) {
            return null;
        }

        return $this->rows[$key] = new SectionTestStateRow($this->nextId++, $section, $name, $value, $at);
    }

    public function update(SectionTestStateRow $row, string $value, DateTimeImmutable $at): bool
    {
        if (!$this->unchanged($row)) {
            return false;
        }
        $this->rows[$row->section . "\0" . $row->name] = new SectionTestStateRow($row->id, $row->section, $row->name, $value, $at);

        return true;
    }

    public function delete(SectionTestStateRow $row): bool
    {
        if (!$this->unchanged($row)) {
            return false;
        }
        unset($this->rows[$row->section . "\0" . $row->name]);

        return true;
    }

    private function unchanged(SectionTestStateRow $row): bool
    {
        $current = $this->rows[$row->section . "\0" . $row->name] ?? null;

        return $current !== null && $current->id === $row->id && $current->updatedAt->getTimestamp() === $row->updatedAt->getTimestamp();
    }
}
