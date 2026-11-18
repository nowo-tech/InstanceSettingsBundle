<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\Expiry\ExpiryDate;
use Nowo\InstanceSettingsBundle\ExpiryNoticeEntity\SecretExpiryNotice;
use Throwable;

use function sprintf;

/**
 * Database sent-markers of the expiry reminders (table `instance_setting_secret_expiry_notice`, 1.21, KIT-88).
 *
 * A claim is one INSERT whose primary key is the {@see ExpiryNoticeKey::hash()}: the database refuses the second one,
 * on every supported platform, whichever worker, process or server tries. Plain DBAL on the connection of the bundle's
 * private entity manager ({@see SettingsEntityManager}): nothing is hydrated or kept between calls (worker mode). Inside
 * a transaction the INSERT runs under its own savepoint, rolled back on any error and released on success, so a
 * refused duplicate or a failed insert leaves the caller's transaction usable (PostgreSQL); errors other than the
 * duplicate are rethrown.
 */
final readonly class DoctrineExpiryNoticeStore implements ExpiryNoticeStoreInterface
{
    /** Savepoint of a claim inside a transaction. */
    private const SAVEPOINT = 'NOWO_IS_EXPIRY_NOTICE';

    /**
     * @param SettingsEntityManager $entityManager the bundle's private manager
     */
    public function __construct(
        private SettingsEntityManager $entityManager,
    ) {
    }

    /**
     * Inserts the marker; false when it exists.
     */
    public function claim(ExpiryNoticeKey $key, DateTimeImmutable $at): bool
    {
        $entityManager = $this->entityManager->get();
        $connection    = $entityManager->getConnection();
        $table         = $entityManager->getClassMetadata(SecretExpiryNotice::class)->getTableName();
        $savepoint     = $connection->isTransactionActive();
        if ($savepoint) {
            $connection->createSavepoint(self::SAVEPOINT);
        }
        try {
            $connection->insert($table, [
                'notice_key' => $key->hash(),
                'section'    => $key->section,
                'name'       => $key->field,
                'expires_on' => new DateTimeImmutable($key->expiresOn . 'T00:00:00', new DateTimeZone('UTC')),
                'threshold'  => $key->threshold,
                'channel'    => $key->channel,
                'recipient'  => $key->recipient,
                'sent_at'    => $at,
            ], ['expires_on' => Types::DATE_IMMUTABLE, 'threshold' => Types::INTEGER, 'sent_at' => Types::DATETIME_IMMUTABLE]);
        } catch (Throwable $e) {
            if ($savepoint) {
                $this->rollbackSavepoint($connection);
            }
            if ($e instanceof UniqueConstraintViolationException) {
                return false;
            }

            throw $e;
        }
        if ($savepoint) {
            $connection->releaseSavepoint(self::SAVEPOINT);
        }

        return true;
    }

    /**
     * Deletes the markers whose expiry date is before `$expiresBefore`, in one statement.
     */
    public function purge(DateTimeImmutable $expiresBefore): int
    {
        $entityManager = $this->entityManager->get();

        return (int) $entityManager->getConnection()->executeStatement(
            sprintf('DELETE FROM %s WHERE expires_on < ?', $entityManager->getClassMetadata(SecretExpiryNotice::class)->getTableName()),
            [ExpiryDate::of($expiresBefore)],
            [Types::DATE_IMMUTABLE],
        );
    }

    /**
     * Back to the claim's savepoint after any error, so PostgreSQL keeps the caller's transaction usable. When the
     * error already ended the transaction (a MySQL deadlock rolls back everything and drops the savepoints), there is
     * nothing to roll back to: that second error is ignored and the first one reaches the caller.
     */
    private function rollbackSavepoint(Connection $connection): void
    {
        try {
            $connection->rollbackSavepoint(self::SAVEPOINT);
        } catch (Throwable) {
            // The transaction is gone already (see above).
        }
    }

    /**
     * Deletes the marker.
     */
    public function release(ExpiryNoticeKey $key): void
    {
        $entityManager = $this->entityManager->get();
        $entityManager->getConnection()->delete($entityManager->getClassMetadata(SecretExpiryNotice::class)->getTableName(), ['notice_key' => $key->hash()]);
    }
}
