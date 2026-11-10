<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use DateTimeImmutable;
use JsonSerializable;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use SensitiveParameter;
use WeakMap;

use const DATE_ATOM;

/**
 * Persistence-neutral, read-only view of one stored setting (1.10, DDD P2): what a {@see SettingValueRepositoryInterface}
 * row holds, without the Doctrine entity, its ORM mapping or its encryption convention.
 *
 * Plain settings carry {@see self::$value}; secrets have {@see self::$isSecret} true and their decrypted value is read
 * with {@see self::secret()} (null when it could not be decrypted: wrong or lost key).
 *
 * The decrypted secret is **not a property** of the object (audit KI-I-M1): it is held in a private side table keyed
 * by the object, so `var_dump()`, `print_r()`, `var_export()`, `(array)`, `get_object_vars()` and Symfony VarDumper /
 * Serializer / PropertyAccess never see it. `json_encode()` and `serialize()` give `***` for a present secret, and an
 * unserialized row has no secret. Rows cannot be cloned (the clone would lose the secret silently).
 */
final class StoredSettingRow implements JsonSerializable
{
    /** Mask written in place of a present secret. */
    public const MASK = '***';

    /**
     * Decrypted secrets by row, outside the object's properties. Weak: an entry lives exactly as long as its row, so
     * nothing outlives the row that holds it (no state shared across requests in worker mode).
     *
     * @var WeakMap<self, string>|null
     */
    private static ?WeakMap $secrets = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (weak side table, entries die with their row; KI-I-M1)

    /**
     * @param string $section section name
     * @param string $name field name inside the section
     * @param bool|float|int|string|null $value plain value (null for secrets)
     * @param bool $isSecret whether the row stores a secret
     * @param string|null $secret decrypted secret (null for plain settings and for an undecryptable one)
     * @param DateTimeImmutable $updatedAt last change
     */
    public function __construct(
        public readonly string $section,
        public readonly string $name,
        public readonly bool|float|int|string|null $value,
        public readonly bool $isSecret,
        #[SensitiveParameter]
        ?string $secret,
        public readonly DateTimeImmutable $updatedAt,
    ) {
        if ($secret !== null) {
            $this->secrets()[$this] = $secret;
        }
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
     * Decrypted secret (null for plain settings, an undecryptable secret or an unserialized row).
     */
    public function secret(): ?string
    {
        return $this->secrets()[$this] ?? null;
    }

    /**
     * `section.field` key of the row.
     */
    public function key(): string
    {
        return $this->section . '.' . $this->name;
    }

    /**
     * JSON view with the secret masked.
     *
     * @return array{section: string, name: string, value: bool|float|int|string|null, isSecret: bool, secret: string|null, updatedAt: string}
     */
    public function jsonSerialize(): array
    {
        return ['updatedAt' => $this->updatedAt->format(DATE_ATOM)] + $this->masked();
    }

    /**
     * Serialized form with the secret masked (an unserialized row has no secret).
     *
     * @return array{section: string, name: string, value: bool|float|int|string|null, isSecret: bool, secret: string|null, updatedAt: DateTimeImmutable}
     */
    public function __serialize(): array
    {
        return $this->masked();
    }

    /**
     * Restores a serialized row without its secret.
     *
     * @param array{section: string, name: string, value: bool|float|int|string|null, isSecret: bool, secret?: string|null, updatedAt: DateTimeImmutable} $data
     */
    public function __unserialize(array $data): void
    {
        $this->section   = $data['section'];
        $this->name      = $data['name'];
        $this->value     = $data['value'];
        $this->isSecret  = $data['isSecret'];
        $this->updatedAt = $data['updatedAt'];
    }

    /**
     * Dump view with the secret masked.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->masked();
    }

    /**
     * Rows are not cloned: the copy would silently lose the secret.
     *
     * @throws LogicException always
     */
    public function __clone()
    {
        throw new LogicException('StoredSettingRow cannot be cloned.');
    }

    /**
     * @return array{section: string, name: string, value: bool|float|int|string|null, isSecret: bool, secret: string|null, updatedAt: DateTimeImmutable}
     */
    private function masked(): array
    {
        return [
            'section'   => $this->section,
            'name'      => $this->name,
            'value'     => $this->value,
            'isSecret'  => $this->isSecret,
            'secret'    => $this->secret() === null ? null : self::MASK,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /**
     * @return WeakMap<self, string>
     */
    private function secrets(): WeakMap
    {
        // @igor-ignore - Weak side table of value objects (KI-I-M1): an entry lives exactly as long as its row.
        return self::$secrets ??= new WeakMap(); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (weak side table, see the property)
    }
}
