<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;

use function sort;
use function sprintf;

use const SORT_STRING;

/**
 * The write lock of one settings section (1.19, Beacon spec 239): a sentinel row of the settings table under the reserved
 * section {@see self::SECTION} (row name = the section name), created when missing and locked in the same statement, inside
 * the caller's transaction. It is held until that transaction ends (commit or rollback; never a session lock, so it is
 * safe behind a transaction-pooling proxy and cannot outlive a request of a long-running worker, audit QG-M2).
 *
 * - MySQL / MariaDB: `INSERT … ON DUPLICATE KEY UPDATE id = id` (an exclusive lock on the row, new or existing; no gap
 *   lock is taken first, so two first writers of a section queue instead of deadlocking, audit QL-L1);
 * - PostgreSQL: `INSERT … ON CONFLICT DO NOTHING`, then `SELECT … FOR UPDATE`;
 * - SQLite: `INSERT … ON CONFLICT DO NOTHING` (a writing transaction locks the whole database).
 *
 * Other platforms are not supported by the settings store; no lock is taken there (the 1.18 behaviour).
 *
 * The reserved section starts with `_`, which no registered section can use ({@see SettingsSectionRegistry::NAME_PATTERN}):
 * the reads of the settings, the exports and the seeds never see these rows. Stateless.
 */
final class SectionLock
{
    /** Reserved section of the sentinel rows. */
    public const SECTION = '_section_lock';

    /**
     * Inside a unit of work: takes the write lock of `$section` and returns its rows as last committed, when the repository
     * supports it ({@see SectionLockingRepositoryInterface}); else a plain read (the 1.18 behaviour of a custom repository).
     *
     * @return list<SettingValue>
     */
    public static function rowsOf(SettingValueRepositoryInterface $repository, string $section): array
    {
        if (!$repository instanceof SectionLockingRepositoryInterface) {
            return $repository->findBySection($section);
        }
        $repository->lockSection($section);

        return $repository->findBySectionForUpdate($section);
    }

    /**
     * Inside a unit of work: takes the write locks of several sections in the lock order (ascending names), so writers of
     * several sections (imports, seeds, raw row writes) never deadlock each other. Nothing for a repository without locks.
     *
     * @param list<string> $sections
     */
    public static function lockAll(SettingValueRepositoryInterface $repository, array $sections): void
    {
        if (!$repository instanceof SectionLockingRepositoryInterface) {
            return;
        }
        sort($sections, SORT_STRING);
        foreach ($sections as $section) {
            $repository->lockSection($section);
        }
    }

    /**
     * Takes the write lock of `$section` inside the open transaction of `$connection`.
     *
     * @param array{section: string, name: string, updated_at: string, id?: string} $columns column names of the settings
     *                                                                                       table (`id` with `$id`)
     * @param int|null $id the id of a new sentinel row when the ids come from a sequence (PostgreSQL with DBAL 3)
     *
     * @throws LogicException outside a transaction (the lock would be released at once)
     */
    public static function acquire(Connection $connection, string $table, array $columns, string $section, DateTimeImmutable $at, ?int $id = null): void
    {
        if (!$connection->isTransactionActive()) {
            throw new LogicException('An instance settings section lock is taken inside a transaction only.');
        }
        $platform = $connection->getDatabasePlatform();
        $insert   = sprintf('INSERT INTO %s (%s, %s, %s) VALUES (?, ?, ?)', $table, $columns['section'], $columns['name'], $columns['updated_at']);
        $values   = [self::SECTION, $section, $at];
        $types    = [Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE];
        if ($id !== null) {
            $insert   = sprintf('INSERT INTO %s (%s, %s, %s, %s) VALUES (?, ?, ?, ?)', $table, $columns['section'], $columns['name'], $columns['updated_at'], $columns['id'] ?? 'id');
            $values[] = $id;
            $types[]  = Types::INTEGER;
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement($insert . ' ON DUPLICATE KEY UPDATE id = id', $values, $types);

            return;
        }
        if (!$platform instanceof PostgreSQLPlatform && !$platform instanceof SQLitePlatform) {
            return;
        }
        $connection->executeStatement(sprintf('%s ON CONFLICT (%s, %s) DO NOTHING', $insert, $columns['section'], $columns['name']), $values, $types);
        if ($platform instanceof PostgreSQLPlatform) {
            $connection->executeQuery(sprintf('SELECT id FROM %s WHERE %s = ? AND %s = ? FOR UPDATE', $table, $columns['section'], $columns['name']), [self::SECTION, $section])->fetchAllAssociative();
        }
    }
}
