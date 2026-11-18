<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\ExpiryNoticeEntity\SecretExpiryNotice;

/**
 * Database sent-markers of the expiry reminders (table `instance_setting_secret_expiry_notice`, 1.21, KIT-88).
 *
 * A claim is one INSERT whose primary key is the {@see ExpiryNoticeKey::hash()}: the database refuses the second one,
 * on every supported platform, whichever worker, process or server tries. Plain DBAL on the connection of the bundle's
 * private entity manager ({@see SettingsEntityManager}): nothing is hydrated or kept between calls (worker mode). Inside
 * a transaction the INSERT runs under its own savepoint, so a refused duplicate leaves the transaction usable
 * (PostgreSQL); any other error reaches the caller untouched.
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
        } catch (UniqueConstraintViolationException) {
            if ($savepoint) {
                $connection->rollbackSavepoint(self::SAVEPOINT);
            }

            return false;
        }
        if ($savepoint) {
            $connection->releaseSavepoint(self::SAVEPOINT);
        }

        return true;
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
