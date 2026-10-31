<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Portability;

use DateTimeInterface;

use function array_diff;
use function array_keys;
use function ctype_digit;
use function is_int;
use function is_string;

/**
 * Versioned JSON envelope of an instance configuration export:
 *
 * ```json
 * {"schema": "nowo-instance-settings", "version": 1, "exported_at": "2026-10-31T10:00:00+00:00",
 *  "settings": {"<section>": {"<field>": value}},
 *  "secrets": {"sealed": true, "algorithm": "doctrine_encrypt:default", "values": {"<section>": {"<field>": "enc:v2:…"}}},
 *  "providers": {"<name>": {…}}}
 * ```
 */
final class PortabilityEnvelope
{
    /** Current envelope version. */
    public const VERSION = 1;

    /** Default `schema` value (configurable with `portability.schema`). */
    public const DEFAULT_SCHEMA = 'nowo-instance-settings';

    /** Top-level keys an envelope may carry. */
    public const KEYS = ['schema', 'version', 'exported_at', 'settings', 'secrets', 'providers'];

    /**
     * Header of a new export.
     *
     * @return array{schema: string, version: int, exported_at: string}
     */
    public static function header(string $schema, DateTimeInterface $exportedAt): array
    {
        return [
            'schema'      => $schema,
            'version'     => self::VERSION,
            'exported_at' => $exportedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Checks schema, version (1 … {@see self::VERSION}) and top-level keys.
     *
     * @param array<string, mixed> $payload
     *
     * @throws PortabilityException `invalid_schema`, `invalid_version`, `unsupported_version`, `unknown_key:<key>`
     */
    public static function assertValid(array $payload, string $schema): int
    {
        if (($payload['schema'] ?? null) !== $schema) {
            throw new PortabilityException('invalid_schema');
        }

        $version = $payload['version'] ?? null;
        if (is_string($version) && $version !== '' && ctype_digit($version)) {
            $version = (int) $version;
        }
        if (!is_int($version)) {
            throw new PortabilityException('invalid_version');
        }
        if ($version < 1 || $version > self::VERSION) {
            throw new PortabilityException('unsupported_version');
        }

        foreach (array_diff(array_keys($payload), self::KEYS) as $unknown) {
            throw new PortabilityException('unknown_key:' . $unknown);
        }

        return $version;
    }
}
