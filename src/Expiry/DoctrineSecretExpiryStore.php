<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Nowo\InstanceSettingsBundle\Doctrine\SequenceId;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\ExpiryEntity\SecretExpiry;

use function array_filter;
use function is_numeric;
use function sprintf;

/**
 * Database store of the expiry dates (table `instance_setting_secret_expiry`, 1.17).
 *
 * Plain DBAL statements on the connection of the bundle's private entity manager ({@see SettingsEntityManager}): no
 * entity is hydrated or kept in an identity map, so nothing outlives a call in worker mode, and a write joins the
 * transaction of the settings rows when {@see self::write()} runs inside one. The table name comes from the mapping
 * (with `doctrine.table_prefix`). The "last announced threshold" is moved with one conditional UPDATE, atomic on every
 * supported platform (MySQL, MariaDB, PostgreSQL, SQLite).
 */
final readonly class DoctrineSecretExpiryStore implements SecretExpiryStoreInterface
{
    /**
     * @param SettingsEntityManager $entityManager the bundle's private manager (shared with the setting repository)
     */
    public function __construct(
        private SettingsEntityManager $entityManager,
    ) {
    }

    /**
     * Every stored date, ordered by section and field.
     */
    public function findAll(): array
    {
        return $this->select('', []);
    }

    /**
     * Stored dates of one section, keyed by field name.
     */
    public function findBySection(string $section): array
    {
        $records = [];
        // 1.19 (audit QQ-I-3): the exact section only (a folding collation matches look-alikes).
        foreach (array_filter($this->select(' WHERE section = ?', [$section]), static fn (SecretExpiryRecord $record): bool => $record->section === $section) as $record) {
            $records[$record->field] = $record;
        }

        return $records;
    }

    /**
     * Sets or removes dates of one section; a new date forgets the announced thresholds.
     */
    public function write(string $section, array $dates, DateTimeImmutable $at): void
    {
        if ($dates === []) {
            return;
        }
        [$connection, $table] = $this->target();
        $stored               = $this->findBySection($section);
        foreach ($dates as $field => $date) {
            $current = $stored[$field] ?? null;
            if ($date === null) {
                if ($current !== null) {
                    $connection->delete($table, ['section' => $section, 'name' => $field]);
                }

                continue;
            }
            $date = ExpiryDate::of($date);
            if ($current === null) {
                $this->insert($connection, $table, $section, $field, $date, $at);

                continue;
            }
            if (ExpiryDate::format($current->expiresOn) !== ExpiryDate::format($date)) {
                // A changed date resets the record: its thresholds are announced again.
                $connection->update($table, ['expires_on' => $date, 'notified_threshold' => null, 'notified_at' => null, 'updated_at' => $at], ['section' => $section, 'name' => $field], [
                    'expires_on' => Types::DATE_IMMUTABLE, 'notified_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE,
                ]);
            }
        }
    }

    /**
     * Moves the last announced threshold of the field's date to `$threshold` with one conditional UPDATE.
     */
    public function claim(string $section, string $field, DateTimeImmutable $expiresOn, int $threshold, DateTimeImmutable $at): bool
    {
        [$connection, $table] = $this->target();
        $moved                = $connection->executeStatement(
            sprintf('UPDATE %s SET notified_threshold = ?, notified_at = ? WHERE section = ? AND name = ? AND expires_on = ? AND (notified_threshold IS NULL OR notified_threshold > ?)', $table),
            [$threshold, $at, $section, $field, ExpiryDate::of($expiresOn), $threshold],
            [Types::INTEGER, Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING, Types::DATE_IMMUTABLE, Types::INTEGER],
        );

        return (int) $moved === 1;
    }

    /**
     * Puts the last announced threshold back to `$previous` when the record still holds the failed claim.
     */
    public function release(string $section, string $field, DateTimeImmutable $expiresOn, int $threshold, ?int $previous): void
    {
        [$connection, $table] = $this->target();
        $connection->executeStatement(
            sprintf('UPDATE %s SET notified_threshold = ?%s WHERE section = ? AND name = ? AND expires_on = ? AND notified_threshold = ?', $table, $previous === null ? ', notified_at = NULL' : ''),
            [$previous, $section, $field, ExpiryDate::of($expiresOn), $threshold],
            [Types::INTEGER, Types::STRING, Types::STRING, Types::DATE_IMMUTABLE, Types::INTEGER],
        );
    }

    /**
     * Inserts the first date of a field. When another request stored one since this one read the table (two editors
     * saving the first date at the same moment, audit I4), the unique key refuses the insert: inside the settings
     * transaction it is rolled back to its own savepoint (so PostgreSQL keeps the outer transaction usable), outside one
     * it is a plain autocommit insert, and the date is written over the other one with one conditional UPDATE — last
     * writer wins, and an equal date keeps its announced thresholds — instead of failing the save.
     *
     * Any other error (audit QC-M1: a MySQL deadlock, which has already rolled back the whole transaction and its
     * savepoints) reaches the caller untouched, so its transaction rolls back at its own level and DBAL's nesting level
     * never stays behind on a kept-alive worker connection. With three or more first saves of the same field at once,
     * MySQL can still make one of them a deadlock victim (audit QC-I1): a retryable {@see RetryableException}.
     */
    private function insert(Connection $connection, string $table, string $section, string $field, DateTimeImmutable $date, DateTimeImmutable $at): void
    {
        $row   = ['section' => $section, 'name' => $field, 'expires_on' => $date, 'notified_threshold' => null, 'notified_at' => null, 'updated_at' => $at];
        $types = ['expires_on' => Types::DATE_IMMUTABLE, 'notified_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE];
        // 1.19: the ids come from a sequence on PostgreSQL with DBAL 3 (no column default).
        $row = SequenceId::into($row, $connection, $this->entityManager->get()->getClassMetadata(SecretExpiry::class));
        // QC-M1: an own savepoint, rolled back ONLY for the unique violation. Any other error (MySQL deadlock 1213,
        // which already rolled back the whole transaction and dropped every savepoint) is left to the caller's
        // transaction untouched: no ROLLBACK TO SAVEPOINT (1305) and no DBAL nesting level left behind.
        $savepoint = $connection->isTransactionActive() ? 'NOWO_IS_EXPIRY_INSERT' : null;
        if ($savepoint !== null) {
            $connection->createSavepoint($savepoint);
        }
        try {
            $connection->insert($table, $row, $types);
        } catch (UniqueConstraintViolationException) {
            if ($savepoint !== null) {
                $connection->rollbackSavepoint($savepoint);
            }
            $connection->executeStatement(
                sprintf('UPDATE %s SET expires_on = ?, notified_threshold = NULL, notified_at = NULL, updated_at = ? WHERE section = ? AND name = ? AND expires_on <> ?', $table),
                [$date, $at, $section, $field, $date],
                [Types::DATE_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING, Types::DATE_IMMUTABLE],
            );

            return;
        }
        if ($savepoint !== null) {
            $connection->releaseSavepoint($savepoint);
        }
    }

    /**
     * @param list<string> $parameters
     *
     * @return list<SecretExpiryRecord>
     */
    private function select(string $where, array $parameters): array
    {
        [$connection, $table] = $this->target();
        $type                 = Type::getType(Types::DATE_IMMUTABLE);
        $platform             = $connection->getDatabasePlatform();
        $records              = [];
        foreach ($connection->fetchAllAssociative(sprintf('SELECT section, name, expires_on, notified_threshold FROM %s%s ORDER BY section ASC, name ASC', $table, $where), $parameters) as $row) {
            // A NOT NULL column: always a date.
            $date      = $type->convertToPHPValue($row['expires_on'], $platform);
            $threshold = $row['notified_threshold'];
            if ($date instanceof DateTimeInterface) {
                $records[] = new SecretExpiryRecord((string) $row['section'], (string) $row['name'], ExpiryDate::of($date), is_numeric($threshold) ? (int) $threshold : null);
            }
        }

        return $records;
    }

    /**
     * The private manager's connection and the (prefixed) table name.
     *
     * @return array{0: Connection, 1: string}
     */
    private function target(): array
    {
        $entityManager = $this->entityManager->get();

        return [$entityManager->getConnection(), $entityManager->getClassMetadata(SecretExpiry::class)->getTableName()];
    }
}
