<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Secret;

use Nowo\DoctrineEncryptBundle\Util\EncryptUtil;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use Throwable;

use function base64_decode;
use function base64_encode;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Seals with DoctrineEncryptBundle's {@see EncryptUtil} (default profile: the same key as the `#[Encrypted]` setting
 * rows). A value that is not sealed, is malformed or does not decrypt with this instance key is refused.
 */
final readonly class DoctrineEncryptSecretSealer implements SecretSealerInterface
{
    /**
     * @param EncryptUtil $encryptUtil DoctrineEncrypt encryption helper
     * @param string|null $profile DoctrineEncrypt profile (null = default profile)
     */
    public function __construct(
        private EncryptUtil $encryptUtil,
        private ?string $profile = null,
    ) {
    }

    /**
     * `enc:v1:` + base64 of the DoctrineEncrypt ciphertext.
     */
    public function seal(string $plaintext): string
    {
        if ($plaintext === '' || $plaintext === '0') {
            throw new PortabilityException('seal_failed');
        }

        // EncryptUtil returns the ciphertext + its marker for every non-empty input.
        return self::PREFIX . base64_encode((string) $this->encryptUtil->encrypt($plaintext, $this->profile));
    }

    /**
     * Decrypts a sealed value with this instance key; fails closed.
     */
    public function unseal(string $sealed): string
    {
        if (!$this->isSealed($sealed)) {
            throw new PortabilityException('invalid_sealed_secret');
        }

        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
        if ($raw === false || !str_ends_with($raw, EncryptUtil::ENCRYPTION_MARKER) || strlen($raw) === strlen(EncryptUtil::ENCRYPTION_MARKER)) {
            throw new PortabilityException('invalid_sealed_secret');
        }

        try {
            $plain = $this->encryptUtil->decrypt($raw, $this->profile);
        } catch (Throwable) {
            $plain = null;
        }

        if ($plain === null || $plain === '') {
            throw new PortabilityException('failed_unseal_secret');
        }

        return $plain;
    }

    /**
     * Whether the value starts with {@see self::PREFIX}.
     */
    public function isSealed(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * `doctrine_encrypt:<profile>`.
     */
    public function algorithm(): string
    {
        return 'doctrine_encrypt:' . ($this->profile ?? 'default');
    }
}
