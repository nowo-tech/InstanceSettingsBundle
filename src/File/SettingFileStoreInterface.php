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
     * Reconciles the orphan marks with the live references: clears `orphaned_at` on every image whose
     * `section.field|sha256` is in `$referenced` (an image referenced again, by a restore, an import or a stale earlier
     * run, is never purged), and marks every other not-yet-orphaned image as orphaned from `$at` (fields removed from
     * code, rows deleted outside the bundle). Returns the number of images (originals) newly marked.
     *
     * @param list<string> $referenced `<section>.<field>|<sha256>` of every live reference
     */
    public function markUnreferenced(array $referenced, DateTimeImmutable $at, bool $dryRun = false): int;

    /**
     * Deletes the images (and variants) orphaned at or before `$orphanedBefore` that are still not referenced.
     * `$referenced` is called right before the delete, inside the store's transaction, and returns the live references
     * (`<section>.<field>|<sha256>`) read again at that moment; a referenced image is never deleted, even if it still
     * carries an orphan mark. Each delete also re-checks the mark, so an image referenced again concurrently (its mark
     * cleared) is kept. Returns the number of images (originals) concerned.
     *
     * @param callable(): list<string> $referenced
     */
    public function purge(DateTimeImmutable $orphanedBefore, callable $referenced, bool $dryRun = false): int;
}
