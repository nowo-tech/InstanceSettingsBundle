<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use RuntimeException;

use function base64_decode;
use function base64_encode;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Reversible fake encryptor bound to a key id: data sealed with another key fails to decrypt.
 */
final readonly class FakeEncryptor implements EncryptorInterface
{
    public function __construct(
        private string $keyId = 'k1',
    ) {
    }

    public function encrypt(string $data): string
    {
        return $this->keyId . ':' . base64_encode($data);
    }

    public function decrypt(string $data): string
    {
        if (!str_starts_with($data, $this->keyId . ':')) {
            throw new RuntimeException('wrong key');
        }

        return (string) base64_decode(substr($data, strlen($this->keyId) + 1), true);
    }
}
