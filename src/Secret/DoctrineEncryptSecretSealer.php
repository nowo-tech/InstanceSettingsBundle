<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Secret;

use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistry;
use Nowo\DoctrineEncryptBundle\Encryptors\MysqlAesEncryptor;
use Nowo\DoctrineEncryptBundle\Util\EncryptUtil;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use Throwable;

use function base64_decode;
use function base64_encode;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Seals with DoctrineEncryptBundle's {@see EncryptUtil} (default profile: the same key as the `#[Encrypted]` setting
 * rows). The encrypted payload is `<context>\n<plaintext>`, so the plaintext is never `''` / `'0'` (values EncryptUtil
 * would leave in clear) and a value only unseals for the context it was sealed for. A value that is not sealed, is
 * malformed, does not carry the encryption marker, uses the unauthenticated MysqlAes profile, does not decrypt with this instance key or belongs to another
 * context is refused.
 */
final readonly class DoctrineEncryptSecretSealer implements SecretSealerInterface
{
    /** Separator between the context and the plaintext inside the encrypted payload. */
    private const SEPARATOR = "\n";

    /**
     * @param EncryptUtil $encryptUtil DoctrineEncrypt encryption helper
     * @param string|null $profile DoctrineEncrypt profile (null = default profile)
     * @param EncryptorRegistry|null $registry lets the sealer refuse an unauthenticated profile (MysqlAes)
     */
    public function __construct(
        private EncryptUtil $encryptUtil,
        private ?string $profile = null,
        private ?EncryptorRegistry $registry = null,
    ) {
    }

    /**
     * `enc:v2:` + base64 of the DoctrineEncrypt ciphertext of `<context>\n<plaintext>`.
     */
    public function seal(string $plaintext, string $context): string
    {
        $this->assertContext($context, 'seal_failed');
        $this->assertAuthenticatedEncryptor();

        // The payload is never '' / '0', so EncryptUtil always encrypts it and appends its marker.
        return self::PREFIX . base64_encode((string) $this->encryptUtil->encrypt($context . self::SEPARATOR . $plaintext, $this->profile));
    }

    /**
     * Decrypts a sealed value with this instance key and checks its context; fails closed.
     */
    public function unseal(string $sealed, string $context): string
    {
        $this->assertContext($context, 'invalid_sealed_secret');
        $this->assertAuthenticatedEncryptor();
        if (!$this->isSealed($sealed)) {
            throw new PortabilityException('invalid_sealed_secret');
        }

        $raw = base64_decode(substr($sealed, strlen(self::PREFIX)), true);
        if ($raw === false || !str_ends_with($raw, EncryptUtil::ENCRYPTION_MARKER) || strlen($raw) === strlen(EncryptUtil::ENCRYPTION_MARKER)) {
            throw new PortabilityException('invalid_sealed_secret');
        }

        try {
            $payload = $this->encryptUtil->decrypt($raw, $this->profile);
        } catch (Throwable) {
            $payload = null;
        }

        // A decryption that "succeeds" with the input unchanged (marker stripped) is no decryption.
        if ($payload === null || $payload === '' || $payload . EncryptUtil::ENCRYPTION_MARKER === $raw) {
            throw new PortabilityException('failed_unseal_secret');
        }

        $prefix = $context . self::SEPARATOR;
        if (!str_starts_with($payload, $prefix)) {
            throw new PortabilityException('secret_context_mismatch');
        }

        $plain = substr($payload, strlen($prefix));
        if ($plain === '') {
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

    /**
     * Refuses the deprecated MysqlAes encryptor (AES-128-ECB, no authentication: sealed values would be malleable and a
     * foreign key would sometimes pass) for sealing.
     */
    private function assertAuthenticatedEncryptor(): void
    {
        if (!$this->registry instanceof EncryptorRegistry) {
            return;
        }

        $encryptor = $this->profile === null ? $this->registry->getDefault() : $this->registry->get($this->profile);
        if ($encryptor instanceof MysqlAesEncryptor) {
            throw new PortabilityException('unsupported_encryptor');
        }
    }

    private function assertContext(string $context, string $reason): void
    {
        if ($context === '' || str_contains($context, self::SEPARATOR)) {
            throw new PortabilityException($reason);
        }
    }
}
