<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\ExpiryNoticeEntity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\InstanceSettingsBundle\Expiry\Notify\DoctrineExpiryNoticeStore;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeKey;

/**
 * Sent-marker of one expiry reminder (1.21, KIT-88): one row per secret, expiry date, threshold, channel and recipient.
 *
 * Mapped only with `expiring_secrets.notify.enabled: true` (hosts without the default notifier keep the 1.20 schema).
 * The primary key is the {@see ExpiryNoticeKey::hash()} of those six values, so the database itself refuses a second
 * marker for the same reminder: two concurrent runs, a restarted worker or a retried run never send it twice. It never
 * holds the secret, only where it is, its date and an opaque recipient id. The store writes the table with DBAL
 * ({@see DoctrineExpiryNoticeStore}); this class only carries the mapping. The table name gets `doctrine.table_prefix`.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
class SecretExpiryNotice
{
    /** Base table name (before `doctrine.table_prefix`). */
    public const TABLE = 'instance_setting_secret_expiry_notice';

    /**
     * Mapping of one row (the store inserts rows with DBAL).
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'notice_key', type: 'string', length: 64, options: ['fixed' => true])]
        private string $noticeKey,
        #[ORM\Column(type: 'string', length: 64)]
        private string $section,
        #[ORM\Column(type: 'string', length: 64)]
        private string $name,
        #[ORM\Column(name: 'expires_on', type: 'date_immutable')]
        private DateTimeImmutable $expiresOn,
        #[ORM\Column(type: 'integer')]
        private int $threshold,
        #[ORM\Column(type: 'string', length: 32)]
        private string $channel,
        #[ORM\Column(type: 'string', length: 128)]
        private string $recipient,
        #[ORM\Column(name: 'sent_at', type: 'datetime_immutable')]
        private DateTimeImmutable $sentAt,
    ) {
    }

    /**
     * Hash of the reminder ({@see ExpiryNoticeKey::hash()}).
     */
    public function getNoticeKey(): string
    {
        return $this->noticeKey;
    }

    /**
     * Section name.
     */
    public function getSection(): string
    {
        return $this->section;
    }

    /**
     * Field name inside the section.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Expiry date the reminder was about.
     */
    public function getExpiresOn(): DateTimeImmutable
    {
        return $this->expiresOn;
    }

    /**
     * Threshold (days before the date).
     */
    public function getThreshold(): int
    {
        return $this->threshold;
    }

    /**
     * Channel (`mail`, `notify_kit` or a host channel).
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * Opaque recipient id.
     */
    public function getRecipient(): string
    {
        return $this->recipient;
    }

    /**
     * When the reminder was claimed for sending.
     */
    public function getSentAt(): DateTimeImmutable
    {
        return $this->sentAt;
    }
}
