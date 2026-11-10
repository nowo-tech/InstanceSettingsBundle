<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use SensitiveParameter;

/**
 * Persistence-neutral, read-only view of one stored setting (1.10, DDD P2): what a {@see SettingValueRepositoryInterface}
 * row holds, without the Doctrine entity, its ORM mapping or its encryption convention.
 *
 * Plain settings carry {@see self::$value}; secrets carry the decrypted {@see self::$secret} (null when it could not be
 * decrypted: wrong or lost key) and {@see self::$isSecret} true. The secret is left out of `var_dump()` / dumps.
 */
final readonly class StoredSettingRow
{
    /**
     * @param string $section section name
     * @param string $name field name inside the section
     * @param bool|float|int|string|null $value plain value (null for secrets)
     * @param bool $isSecret whether the row stores a secret
     * @param string|null $secret decrypted secret (null for plain settings and for an undecryptable one)
     * @param DateTimeImmutable $updatedAt last change
     */
    public function __construct(
        public string $section,
        public string $name,
        public bool|float|int|string|null $value,
        public bool $isSecret,
        #[SensitiveParameter]
        public ?string $secret,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * View of a stored entity row.
     */
    public static function fromEntity(SettingValue $row): self
    {
        return new self(
            $row->getSection(),
            $row->getName(),
            $row->getValue(),
            $row->getSecretValue() !== null,
            $row->getSecret(),
            $row->getUpdatedAt(),
        );
    }

    /**
     * `section.field` key of the row.
     */
    public function key(): string
    {
        return $this->section . '.' . $this->name;
    }

    /**
     * Dump view without the secret.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'section'   => $this->section,
            'name'      => $this->name,
            'value'     => $this->value,
            'isSecret'  => $this->isSecret,
            'secret'    => $this->secret === null ? null : '***',
            'updatedAt' => $this->updatedAt,
        ];
    }
}
