<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use DateTimeImmutable;

/**
 * Storage of the image setting files (1.4). The default store keeps them in the database
 * ({@see DoctrineSettingFileStore}); this interface is the seam for another backend.
 *
 * Images are bound to their `(section, field)` and content-addressed inside it by the SHA-256 of the re-encoded
 * original. Implementations must be stateless between requests (no bytes kept in memory).
 */
interface SettingFileStoreInterface
{
    /**
     * Metadata of the stored row of a field whose hash starts with `$hashPrefix` (≥ 12 hex characters), or null.
     */
    public function find(string $section, string $field, string $hashPrefix, int $variant): ?SettingFileRecord;

    /**
     * Metadata of every row (original and variants) of one image of a field, keyed by variant (0 = original).
     *
     * @return array<int, SettingFileRecord>
     */
    public function findImage(string $section, string $field, string $sha256): array;

    /**
     * Bytes of a stored row.
     */
    public function read(SettingFileRecord $record): string;

    /**
     * Stores an image and its variants for a field (inside the caller's transaction); an image already stored for the
     * field is referenced again (its `orphaned_at` is cleared).
     */
    public function save(string $section, string $field, ProcessedImage $image, DateTimeImmutable $at): void;

    /**
     * Marks an image of a field (and its variants) as no longer referenced, from `$at`.
     */
    public function orphan(string $section, string $field, string $sha256, DateTimeImmutable $at): void;

    /**
     * Marks every not-yet-orphaned image whose `section.field|sha256` is not in `$referenced` as orphaned from `$at`
     * (fields removed from code, rows deleted outside the bundle). Returns the number of images (originals) concerned.
     *
     * @param list<string> $referenced `<section>.<field>|<sha256>` of every live reference
     */
    public function markUnreferenced(array $referenced, DateTimeImmutable $at, bool $dryRun = false): int;

    /**
     * Deletes the images (and variants) orphaned at or before `$orphanedBefore`. Returns the number of images
     * (originals) concerned.
     */
    public function purge(DateTimeImmutable $orphanedBefore, bool $dryRun = false): int;
}
