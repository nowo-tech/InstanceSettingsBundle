<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;

use function str_ends_with;
use function strlen;
use function substr;

/**
 * One stored instance setting: `(section, name)` with either a plain JSON value or an encrypted secret.
 *
 * A missing row means "use the field default". {@see $secretValue} is encrypted at rest by DoctrineEncryptBundle
 * (`#[Encrypted]`, default profile) and decrypted in place when the row is loaded. The table name
 * `instance_setting_value` gets the configurable `doctrine.table_prefix` (REQ-ORM-002).
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: self::UNIQUE_INDEX, columns: ['section', 'name'])]
class SettingValue
{
    /** Base table name (before `doctrine.table_prefix`). */
    public const TABLE = 'instance_setting_value';

    /**
     * Terminator appended to every stored secret before encryption. The stored plaintext therefore never ends with
     * DoctrineEncrypt's `<ENC>` marker (which would make the subscriber skip encryption) and is never `''` / `'0'`
     * (which EncryptUtil leaves unencrypted); a loaded value without it was not decrypted (wrong / lost key) and is
     * treated as no secret.
     */
    public const SECRET_TERMINATOR = '|';

    /** Base name of the `(section, name)` unique index (before `doctrine.table_prefix`). */
    public const UNIQUE_INDEX = 'uniq_instance_setting_value_section_name';

    /** Doctrine assigns this via reflection after persist / hydrate. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Plain value (JSON scalar); null for secrets. */
    #[ORM\Column(name: 'value', type: 'json', nullable: true)]
    private bool|float|int|string|null $value = null;

    /** Secret value + {@see self::SECRET_TERMINATOR}, encrypted at rest; null for plain settings. */
    #[ORM\Column(name: 'secret_value', type: 'text', nullable: true)]
    #[Encrypted]
    private ?string $secretValue = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * Creates the row of one setting.
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        private string $section,
        #[ORM\Column(type: 'string', length: 64)]
        private string $name,
        DateTimeImmutable $updatedAt,
    ) {
        $this->updatedAt = $updatedAt;
    }

    /**
     * Database id (null until persisted).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @internal Doctrine assigns the id through reflection; tests and fixtures may set it
     */
    public function setId(int $id): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->id = $id;

        return $this;
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
     * Plain value (null for secrets).
     */
    public function getValue(): bool|float|int|string|null
    {
        return $this->value;
    }

    /**
     * Decrypted secret without its terminator; null for plain settings and for a value that was not decrypted
     * (missing terminator: wrong or lost encryption key).
     */
    public function getSecret(): ?string
    {
        $raw = $this->secretValue;
        if ($raw === null || !str_ends_with($raw, self::SECRET_TERMINATOR)) {
            return null;
        }

        return substr($raw, 0, -strlen(self::SECRET_TERMINATOR));
    }

    /**
     * Low-level accessor of the encrypted column (read by DoctrineEncryptBundle through PropertyAccess): the stored
     * form, terminator included. Application code uses {@see self::getSecret()}.
     *
     * @internal
     */
    public function getSecretValue(): ?string
    {
        return $this->secretValue;
    }

    /**
     * Last change.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Stores a plain value (clears any secret).
     */
    public function storeValue(bool|float|int|string|null $value, DateTimeImmutable $at): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->value = $value;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->secretValue = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = $at;

        return $this;
    }

    /**
     * Stores a secret (encrypted on flush; clears any plain value).
     */
    public function storeSecret(string $secret, DateTimeImmutable $at): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->secretValue = $secret . self::SECRET_TERMINATOR;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->value = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = $at;

        return $this;
    }

    /**
     * Low-level accessor of the encrypted column: DoctrineEncryptBundle writes the ciphertext / plaintext through it
     * (Symfony PropertyAccess needs a one-argument setter). Application code uses {@see self::storeSecret()}.
     *
     * @internal
     */
    public function setSecretValue(?string $secretValue): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->secretValue = $secretValue;
    }
}
