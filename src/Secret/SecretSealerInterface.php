<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Secret;

use Nowo\InstanceSettingsBundle\Portability\PortabilityException;

/**
 * Seals secrets for a portable export with the instance encryption key (so only an instance holding the same key can
 * import them) and unseals them on import, failing closed.
 */
interface SecretSealerInterface
{
    /** Prefix of every sealed value. */
    public const PREFIX = 'enc:v1:';

    /**
     * Sealed form (`enc:v1:<base64>`) of a non-empty secret.
     *
     * @throws PortabilityException `seal_failed`
     */
    public function seal(string $plaintext): string;

    /**
     * Plaintext of a sealed value.
     *
     * @throws PortabilityException `invalid_sealed_secret` (not sealed / malformed) or `failed_unseal_secret` (wrong key)
     */
    public function unseal(string $sealed): string;

    /**
     * Whether a value carries the sealed prefix.
     */
    public function isSealed(string $value): bool;

    /**
     * Algorithm identifier written to the export envelope.
     */
    public function algorithm(): string;
}
