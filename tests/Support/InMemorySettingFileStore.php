<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\File\ProcessedImage;
use Nowo\InstanceSettingsBundle\File\SettingFileRecord;
use Nowo\InstanceSettingsBundle\File\SettingFileStoreInterface;

use function array_flip;
use function str_starts_with;

/**
 * In-memory file store (unit tests); counts byte reads.
 */
final class InMemorySettingFileStore implements SettingFileStoreInterface
{
    /** @var array<int, array{record: SettingFileRecord, bytes: string}> */
    public array $rows = [];

    public int $reads = 0;

    public int $lookups = 0;

    private int $nextId = 1;

    public function find(string $section, string $field, string $hashPrefix, int $variant): ?SettingFileRecord
    {
        ++$this->lookups;
        foreach ($this->rows as $row) {
            $record = $row['record'];
            if ($record->section === $section && $record->field === $field && $record->variant === $variant && str_starts_with($record->sha256, $hashPrefix)) {
                return $record;
            }
        }

        return null;
    }

    public function findImage(string $section, string $field, string $sha256): array
    {
        ++$this->lookups;
        $records = [];
        foreach ($this->rows as $row) {
            $record = $row['record'];
            if ($record->section === $section && $record->field === $field && $record->sha256 === $sha256) {
                $records[$record->variant] = $record;
            }
        }

        return $records;
    }

    public function read(SettingFileRecord $record): string
    {
        ++$this->reads;

        return $this->rows[$record->id]['bytes'] ?? '';
    }

    public function save(string $section, string $field, ProcessedImage $image, DateTimeImmutable $at): void
    {
        $sha = $image->sha256();
        if ($this->findImage($section, $field, $sha) !== []) {
            $this->setOrphanedAt($section, $field, $sha, null);

            return;
        }
        foreach ([0 => $image->original] + $image->variants as $variant => $encoded) {
            $id              = $this->nextId++;
            $this->rows[$id] = ['record' => new SettingFileRecord($id, $section, $field, $sha, $variant, $encoded->mimeType, $encoded->width, $encoded->height, $encoded->byteSize()), 'bytes' => $encoded->bytes];
        }
    }

    public function orphan(string $section, string $field, string $sha256, DateTimeImmutable $at): void
    {
        $this->setOrphanedAt($section, $field, $sha256, $at);
    }

    public function markUnreferenced(array $referenced, DateTimeImmutable $at, bool $dryRun = false): int
    {
        $live  = array_flip($referenced);
        $count = 0;
        foreach ($this->rows as $row) {
            $record = $row['record'];
            if ($record->variant !== 0 || $record->orphanedAt !== null || isset($live[$record->section . '.' . $record->field . '|' . $record->sha256])) {
                continue;
            }
            ++$count;
            if (!$dryRun) {
                $this->setOrphanedAt($record->section, $record->field, $record->sha256, $at);
            }
        }

        return $count;
    }

    public function purge(DateTimeImmutable $orphanedBefore, bool $dryRun = false): int
    {
        $count = 0;
        foreach ($this->rows as $id => $row) {
            $record = $row['record'];
            if ($record->orphanedAt === null || $record->orphanedAt > $orphanedBefore) {
                continue;
            }
            if ($record->variant === 0) {
                ++$count;
            }
            if (!$dryRun) {
                unset($this->rows[$id]);
            }
        }

        return $count;
    }

    private function setOrphanedAt(string $section, string $field, string $sha256, ?DateTimeImmutable $at): void
    {
        foreach ($this->rows as $id => $row) {
            $r = $row['record'];
            if ($r->section === $section && $r->field === $field && $r->sha256 === $sha256) {
                $this->rows[$id]['record'] = new SettingFileRecord($r->id, $r->section, $r->field, $r->sha256, $r->variant, $r->mimeType, $r->width, $r->height, $r->byteSize, $at);
            }
        }
    }
}
