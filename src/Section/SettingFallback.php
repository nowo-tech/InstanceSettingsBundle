<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Seed\FallbackSeeder;
use SensitiveParameter;

use function preg_match;
use function sprintf;

/**
 * The bootstrap value a section exposes for one of its fields (typically the `%env(...)%` / YAML value the section
 * used while nothing was stored), read by {@see FallbackSeeder}.
 *
 * Immutable. A secret fallback is never printed: {@see self::__debugInfo()} masks it and the seeder reports field
 * names only. Build it with {@see self::plain()} or {@see self::secret()}.
 */
final readonly class SettingFallback
{
    /**
     * @param string $field field name of the section ({@see SettingField::NAME_PATTERN})
     * @param bool|float|int|string|null $value bootstrap value (null or '' = no fallback)
     * @param bool $secret whether the value is a secret (must match the field: a secret is never stored in clear)
     */
    public function __construct(
        public string $field,
        #[SensitiveParameter]
        public bool|float|int|string|null $value,
        public bool $secret = false,
    ) {
        if (preg_match(SettingField::NAME_PATTERN, $field) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid setting fallback field name "%s".', $field));
        }
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
            'value'  => $this->secret && $this->value !== null ? '***' : $this->value,
            'secret' => $this->secret,
        ];
    }
}
