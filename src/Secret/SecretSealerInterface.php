<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Secret;

use Nowo\InstanceSettingsBundle\Portability\PortabilityException;

/**
 * Seals secrets for a portable export with the instance encryption key (so only an instance holding the same key can
 * import them) and unseals them on import, failing closed.
 *
 * Every sealed value is bound to a context (the export schema and the `<section>.<field>` key): a value sealed for
 * one field is refused for another one, so a ciphertext cannot be moved between secret fields.
 */
interface SecretSealerInterface
{
    /** Prefix of every sealed value (v2: context-bound). */
    public const PREFIX = 'enc:v2:';

    /**
     * Sealed form (`enc:v2:<base64>`) of a secret, bound to `$context`.
     *
     * @param string $context binding context, e.g. `nowo-instance-settings|mailer.dsn` (no line break)
     *
     * @throws PortabilityException `seal_failed`
     */
    public function seal(string $plaintext, string $context): string;

    /**
     * Plaintext of a sealed value, checked against `$context`.
     *
     * @throws PortabilityException `invalid_sealed_secret` (not sealed / malformed), `failed_unseal_secret` (wrong key)
     *                              or `secret_context_mismatch` (sealed for another field / schema)
     */
    public function unseal(string $sealed, string $context): string;

    /**
     * Whether a value carries the sealed prefix.
     */
    public function isSealed(string $value): bool;

    /**
     * Algorithm identifier written to the export envelope.
     */
    public function algorithm(): string;
}
