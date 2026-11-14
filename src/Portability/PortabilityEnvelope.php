<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Portability;

use DateTimeInterface;

use function array_diff;
use function array_key_exists;
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
 *
 * Version 2 (1.4) adds `files`: `{"<section>.<field>": {"type": "image/png", "sha256": "…", "data": "<base64>"}}` for
 * image fields, whose `settings` value is their `isf:<sha256>` reference. An export without image fields stays
 * version 1, so older instances still import it.
 *
 * Version 3 (1.17) adds `secret_expiry`: `{"<section>": {"<field>": "2027-01-31"}}`, the expiry dates of expiring
 * secrets, in clear (a date is not a secret; the secret itself still travels only sealed, under `secrets`). Only an
 * export that carries at least one date is version 3, so every other export stays readable by 1.4 – 1.16.
 */
final class PortabilityEnvelope
{
    /** Current envelope version (2: `files`, 1.4). */
    public const VERSION = 2;

    /** Version of envelopes without image fields (readable by every 1.x). */
    public const VERSION_WITHOUT_FILES = 1;

    /** Version of envelopes carrying `secret_expiry` (1.17); the highest version this bundle reads. */
    public const VERSION_WITH_SECRET_EXPIRY = 3;

    /** Default `schema` value (configurable with `portability.schema`). */
    public const DEFAULT_SCHEMA = 'nowo-instance-settings';

    /** Top-level keys an envelope may carry. */
    public const KEYS = ['schema', 'version', 'exported_at', 'settings', 'secrets', 'providers', 'files', 'secret_expiry'];

    /**
     * Header of a new export.
     *
     * @param int $version {@see self::VERSION}, or {@see self::VERSION_WITHOUT_FILES} when no image field is exported
     *
     * @return array{schema: string, version: int, exported_at: string}
     */
    public static function header(string $schema, DateTimeInterface $exportedAt, int $version = self::VERSION): array
    {
        return [
            'schema'      => $schema,
            'version'     => $version,
            'exported_at' => $exportedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Checks schema, version (1 … {@see self::VERSION_WITH_SECRET_EXPIRY}) and top-level keys (`files` only from
     * version 2, `secret_expiry` only from version 3).
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
        if ($version < 1 || $version > self::VERSION_WITH_SECRET_EXPIRY) {
            throw new PortabilityException('unsupported_version');
        }

        foreach (array_diff(array_keys($payload), self::KEYS) as $unknown) {
            throw new PortabilityException('unknown_key:' . $unknown);
        }
        if ($version < self::VERSION && array_key_exists('files', $payload)) {
            throw new PortabilityException('unknown_key:files');
        }
        if ($version < self::VERSION_WITH_SECRET_EXPIRY && array_key_exists('secret_expiry', $payload)) {
            throw new PortabilityException('unknown_key:secret_expiry');
        }

        return $version;
    }
}
