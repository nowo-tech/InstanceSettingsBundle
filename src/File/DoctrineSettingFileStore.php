<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\FileEntity\SettingFile;

use function array_flip;
use function count;
use function is_resource;
use function sprintf;
use function stream_get_contents;
use function strlen;

/**
 * Database store of the image setting files (table `instance_setting_file`, spec 220 §5.5).
 *
 * Metadata is read with scalar DQL (the blob is never hydrated for a lookup, an ETag check or a purge); the bytes are
 * read only by {@see self::read()}. Since 1.11 (architecture bar rule 6) it works on the bundle's private entity
 * manager ({@see SettingsEntityManager}, shared with the setting repository): a save flushes only the file rows and
 * no application manager is flushed or reset. Worker-safe: the private manager is replaced when closed and the rows
 * are detached right after each write; nothing is memoized.
 */
final readonly class DoctrineSettingFileStore implements SettingFileStoreInterface
{
    private const COLUMNS = 'f.id, f.section, f.name, f.sha256, f.variant, f.mimeType, f.width, f.height, f.byteSize, f.orphanedAt';

    private SettingsEntityManager $entityManager;

    /**
     * @param ManagerRegistry $registry Doctrine registry
     * @param string|null $entityManagerName `doctrine.entity_manager` (null = the manager that maps the bundle entities)
     * @param SettingsEntityManager|null $entityManager the bundle's private manager (shared with the setting repository)
     */
    public function __construct(
        ManagerRegistry $registry,
        ?string $entityManagerName = null,
        ?SettingsEntityManager $entityManager = null,
    ) {
        $this->entityManager = $entityManager ?? new SettingsEntityManager($registry, $entityManagerName);
    }

    /**
     * Metadata of a field's row whose hash starts with `$hashPrefix`; null when none, or when the prefix is ambiguous.
     */
    public function find(string $section, string $field, string $hashPrefix, int $variant): ?SettingFileRecord
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->entityManager()
            ->createQuery(sprintf('SELECT %s FROM %s f WHERE f.section = :section AND f.name = :name AND f.variant = :variant AND f.sha256 LIKE :prefix', self::COLUMNS, SettingFile::class))
            ->setParameters(['section' => $section, 'name' => $field, 'variant' => $variant, 'prefix' => $hashPrefix . '%'])
            ->setMaxResults(2)
            ->getArrayResult();

        return count($rows) === 1 ? $this->record($rows[0]) : null;
    }

    /**
     * Metadata of every row of one image, keyed by variant.
     */
    public function findImage(string $section, string $field, string $sha256): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->entityManager()
            ->createQuery(sprintf('SELECT %s FROM %s f WHERE f.section = :section AND f.name = :name AND f.sha256 = :sha ORDER BY f.variant ASC', self::COLUMNS, SettingFile::class))
            ->setParameters(['section' => $section, 'name' => $field, 'sha' => $sha256])
            ->getArrayResult();

        $records = [];
        foreach ($rows as $row) {
            $record                    = $this->record($row);
            $records[$record->variant] = $record;
        }

        return $records;
    }

    /**
     * Bytes of a stored row ('' when it disappeared meanwhile).
     */
    public function read(SettingFileRecord $record): string
    {
        $content = $this->entityManager()
            ->createQuery(sprintf('SELECT f.content FROM %s f WHERE f.id = :id', SettingFile::class))
            ->setParameter('id', $record->id)
            ->getOneOrNullResult();
        $content = $content['content'] ?? null;
        if (is_resource($content)) {
            $content = stream_get_contents($content);
        }

        return (string) $content;
    }

    /**
     * Inserts the image and its variants, or clears `orphaned_at` when the field already has this image.
     */
    public function save(string $section, string $field, ProcessedImage $image, DateTimeImmutable $at): void
    {
        $sha = $image->sha256();
        if ($this->findImage($section, $field, $sha) !== []) {
            $this->setOrphanedAt($section, $field, $sha, null);

            return;
        }

        $entityManager = $this->entityManager();
        foreach ([SettingFile::ORIGINAL => $image->original] + $image->variants as $variant => $encoded) {
            $entityManager->persist(new SettingFile($section, $field, $sha, $variant, $encoded->mimeType, $encoded->width, $encoded->height, strlen($encoded->bytes), $encoded->bytes, $at));
        }
        $entityManager->flush();
        // The rows hold the bytes: detach them at once so no blob stays in the identity map after this call.
        foreach ($entityManager->getUnitOfWork()->getIdentityMap()[SettingFile::class] ?? [] as $entity) {
            $entityManager->detach($entity);
        }
    }

    /**
     * Sets `orphaned_at` on every row of the image.
     */
    public function orphan(string $section, string $field, string $sha256, DateTimeImmutable $at): void
    {
        $this->setOrphanedAt($section, $field, $sha256, $at);
    }

    /**
     * Clears the marks of the referenced images and marks the images whose reference is not live any more.
     */
    public function markUnreferenced(array $referenced, DateTimeImmutable $at, bool $dryRun = false): int
    {
        $live = array_flip($referenced);
        /** @var list<array{section: string, name: string, sha256: string, orphanedAt: DateTimeImmutable|null}> $rows */
        $rows = $this->entityManager()
            ->createQuery(sprintf('SELECT f.section, f.name, f.sha256, f.orphanedAt FROM %s f WHERE f.variant = 0', SettingFile::class))
            ->getArrayResult();

        $count = 0;
        foreach ($rows as $row) {
            $isLive = isset($live[$row['section'] . '.' . $row['name'] . '|' . $row['sha256']]);
            if ($isLive === ($row['orphanedAt'] === null)) {
                continue;
            }
            if (!$isLive) {
                ++$count;
            }
            if (!$dryRun) {
                $this->setOrphanedAt($row['section'], $row['name'], $row['sha256'], $isLive ? null : $at);
            }
        }

        return $count;
    }

    /**
     * Deletes the expired orphans that are still unreferenced, in one transaction with the re-read of the references.
     */
    public function purge(DateTimeImmutable $orphanedBefore, callable $referenced, bool $dryRun = false): int
    {
        $entityManager = $this->entityManager();

        // @igor-ignore - The closure runs synchronously inside the transaction and is not stored.
        return (int) $entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager) use ($orphanedBefore, $referenced, $dryRun): int {
            /** @var list<array{section: string, name: string, sha256: string}> $rows */
            $rows = $entityManager
                ->createQuery(sprintf('SELECT f.section, f.name, f.sha256 FROM %s f WHERE f.variant = 0 AND f.orphanedAt IS NOT NULL AND f.orphanedAt <= :before', SettingFile::class))
                ->setParameter('before', $orphanedBefore, 'datetime_immutable')
                ->getArrayResult();
            if ($rows === []) {
                return 0;
            }

            // Re-read right before deleting: a reference written since the marks were set keeps its image.
            $live  = array_flip($referenced());
            $count = 0;
            foreach ($rows as $row) {
                if (isset($live[$row['section'] . '.' . $row['name'] . '|' . $row['sha256']])) {
                    continue;
                }
                ++$count;
                if ($dryRun) {
                    continue;
                }
                // The mark is checked again by the delete itself: a concurrent save that references the image again
                // clears it, and the row is then kept.
                $entityManager
                    ->createQuery(sprintf('DELETE FROM %s f WHERE f.section = :section AND f.name = :name AND f.sha256 = :sha AND f.orphanedAt IS NOT NULL AND f.orphanedAt <= :before', SettingFile::class))
                    ->setParameter('section', $row['section'])
                    ->setParameter('name', $row['name'])
                    ->setParameter('sha', $row['sha256'])
                    ->setParameter('before', $orphanedBefore, 'datetime_immutable')
                    ->execute();
            }

            return $count;
        });
    }

    private function setOrphanedAt(string $section, string $field, string $sha256, ?DateTimeImmutable $at): void
    {
        $this->entityManager()
            ->createQuery(sprintf('UPDATE %s f SET f.orphanedAt = :at WHERE f.section = :section AND f.name = :name AND f.sha256 = :sha', SettingFile::class))
            ->setParameter('at', $at, 'datetime_immutable')
            ->setParameter('section', $section)
            ->setParameter('name', $field)
            ->setParameter('sha', $sha256)
            ->execute();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function record(array $row): SettingFileRecord
    {
        /* @var array{id: int|string, section: string, name: string, sha256: string, variant: int|string, mimeType: string, width: int|string, height: int|string, byteSize: int|string, orphanedAt: DateTimeImmutable|null} $row */
        return new SettingFileRecord((int) $row['id'], $row['section'], $row['name'], $row['sha256'], (int) $row['variant'], $row['mimeType'], (int) $row['width'], (int) $row['height'], (int) $row['byteSize'], $row['orphanedAt']);
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->entityManager->get();
    }
}
