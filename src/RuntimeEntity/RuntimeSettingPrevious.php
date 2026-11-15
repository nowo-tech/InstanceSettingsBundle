<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\RuntimeEntity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;
use Nowo\InstanceSettingsBundle\Runtime\InstanceSettingsRuntimeWriter;

/**
 * 1.20 (`runtime_setting.enabled` only): the previous value of a rotated setting, accepted until `expiresAt` (UTC
 * wall-clock time). Written through DBAL by {@see InstanceSettingsRuntimeWriter}:
 * the stored form of the settings row is copied as is (a secret stays encrypted, terminator included). The secret column
 * is `#[Encrypted]` so DoctrineEncrypt's `rotate-keys` / `encrypt:database` / `decrypt:database` cover it.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: self::UNIQUE_INDEX, columns: ['section', 'name'])]
#[ORM\Index(name: self::EXPIRY_INDEX, columns: ['expires_at'])]
class RuntimeSettingPrevious
{
    /** Base table name (before `doctrine.table_prefix`). */
    public const TABLE = 'instance_setting_runtime_previous';

    public const UNIQUE_INDEX = 'uniq_is_runtime_previous';

    public const EXPIRY_INDEX = 'idx_is_runtime_previous_exp';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Plain previous value (JSON scalar); null for a secret. */
    #[ORM\Column(name: 'value', type: 'json', nullable: true)]
    private bool|float|int|string|null $value = null;

    /** Previous secret + terminator, encrypted at rest; null for a plain value. */
    #[ORM\Column(name: 'secret_value', type: 'text', nullable: true)]
    #[Encrypted]
    private ?string $secretValue = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        private string $section,
        #[ORM\Column(type: 'string', length: 64)]
        private string $name,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        private DateTimeImmutable $expiresAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @internal Doctrine assigns the id through reflection; tests may set it
     */
    public function setId(int $id): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->id = $id;

        return $this;
    }

    /**
     * @internal the writer stores the copied JSON value through DBAL; tests may set it
     */
    public function setValue(bool|float|int|string|null $value): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->value = $value;

        return $this;
    }

    public function getSection(): string
    {
        return $this->section;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): bool|float|int|string|null
    {
        return $this->value;
    }

    /**
     * @internal the stored form (read by DoctrineEncryptBundle through PropertyAccess)
     */
    public function getSecretValue(): ?string
    {
        return $this->secretValue;
    }

    /**
     * @internal written by DoctrineEncryptBundle through PropertyAccess
     */
    public function setSecretValue(?string $secretValue): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->secretValue = $secretValue;
    }

    /**
     * UTC wall-clock time.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
