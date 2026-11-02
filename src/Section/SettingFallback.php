<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Nowo\InstanceSettingsBundle\Seed\FallbackSeeder;
use SensitiveParameter;

use function preg_match;
use function sprintf;

/**
 * The bootstrap value a section exposes for one of its fields (typically the `%env(...)%` / YAML value the section
 * used while nothing was stored), read by {@see FallbackSeeder}.
 *
 * Immutable. A secret fallback is never printed: the value is held in an opaque {@see FallbackValue}, so `var_export`,
 * `(array)`, `json_encode` ({@see self::jsonSerialize()}) and dumps ({@see self::__debugInfo()}) never show it,
 * serialization is refused, and the seeder reports field names only. Read it with {@see self::value()}. Build it with
 * {@see self::plain()} or {@see self::secret()}.
 */
final readonly class SettingFallback implements JsonSerializable
{
    private FallbackValue $value;

    /**
     * @param string $field field name of the section ({@see SettingField::NAME_PATTERN})
     * @param bool|float|int|string|null $value bootstrap value (null or '' = no fallback)
     * @param bool $secret whether the value is a secret (must match the field: a secret is never stored in clear)
     */
    public function __construct(
        public string $field,
        #[SensitiveParameter]
        bool|float|int|string|null $value,
        public bool $secret = false,
    ) {
        if (preg_match(SettingField::NAME_PATTERN, $field) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid setting fallback field name "%s".', $field));
        }
        $this->value = new FallbackValue($value);
    }

    /**
     * The bootstrap value (null or '' = no fallback).
     */
    public function value(): bool|float|int|string|null
    {
        return $this->value->get();
    }

    /**
     * JSON representation with a secret value masked.
     *
     * @return array<string, bool|float|int|string|null>
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * Refused: a fallback may hold a secret and must not be persisted or queued.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A setting fallback cannot be serialized.');
    }

    /**
     * Plain (non-secret) fallback.
     */
    public static function plain(string $field, bool|float|int|string|null $value): self
    {
        return new self($field, $value);
    }

    /**
     * Secret fallback (API key, token, DSN): stored encrypted, never printed.
     */
    public static function secret(string $field, #[SensitiveParameter] ?string $value): self
    {
        return new self($field, $value, true);
    }

    /**
     * Debug representation with a secret value masked.
     *
     * @return array<string, bool|float|int|string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'field'  => $this->field,
            'value'  => $this->secret && $this->value() !== null ? '***' : $this->value(),
            'secret' => $this->secret,
        ];
    }
}
