<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\ExpiryEntity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\InstanceSettingsBundle\Expiry\DoctrineSecretExpiryStore;

/**
 * Expiry date of one expiring secret field (1.17), stored next to its secret: one row per `(section, name)`.
 *
 * Mapped only with `expiring_secrets.enabled: true` (hosts without expiring secrets keep the 1.16 schema). It never
 * holds the secret: only the calendar date (`expires_on`, no time, no zone) and the last expiry threshold announced for
 * that date (`notified_threshold`, null = none yet), which survives restarts and worker mode. A new date resets it. The
 * store reads and writes the table with DBAL ({@see DoctrineSecretExpiryStore}); this class only carries the mapping.
 * The table name gets `doctrine.table_prefix` (REQ-ORM-002).
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: self::UNIQUE_INDEX, columns: ['section', 'name'])]
class SecretExpiry
{
    /** Base table name (before `doctrine.table_prefix`). */
    public const TABLE = 'instance_setting_secret_expiry';

    /** Base name of the `(section, name)` unique index (before `doctrine.table_prefix`). */
    public const UNIQUE_INDEX = 'uniq_instance_setting_secret_expiry';

    /** Doctrine assigns this on insert. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    /** Last threshold (days) announced for {@see self::$expiresOn}; null = none yet. */
    #[ORM\Column(name: 'notified_threshold', type: 'integer', nullable: true)]
    private ?int $notifiedThreshold = null; // @phpstan-ignore property.unusedType (written by the store with DBAL)

    /** When {@see self::$notifiedThreshold} was announced. */
    #[ORM\Column(name: 'notified_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $notifiedAt = null; // @phpstan-ignore property.unusedType (written by the store with DBAL)

    /**
     * Mapping of one row (the store inserts rows with DBAL).
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        private string $section,
        #[ORM\Column(type: 'string', length: 64)]
        private string $name,
        #[ORM\Column(name: 'expires_on', type: 'date_immutable')]
        private DateTimeImmutable $expiresOn,
        #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Database id (null until inserted).
     */
    public function getId(): ?int
    {
        return $this->id;
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
     * Expiry date.
     */
    public function getExpiresOn(): DateTimeImmutable
    {
        return $this->expiresOn;
    }

    /**
     * Last threshold announced for the date (null = none yet).
     */
    public function getNotifiedThreshold(): ?int
    {
        return $this->notifiedThreshold;
    }

    /**
     * When the last threshold was announced.
     */
    public function getNotifiedAt(): ?DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    /**
     * Last change of the date.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
