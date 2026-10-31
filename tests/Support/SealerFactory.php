<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistry;
use Nowo\DoctrineEncryptBundle\Util\EncryptUtil;
use Nowo\InstanceSettingsBundle\Secret\DoctrineEncryptSecretSealer;

/**
 * Real {@see DoctrineEncryptSecretSealer} on a {@see FakeEncryptor} bound to a key id.
 */
final class SealerFactory
{
    public static function create(string $keyId = 'k1'): DoctrineEncryptSecretSealer
    {
        return new DoctrineEncryptSecretSealer(new EncryptUtil(new EncryptorRegistry(['default' => new FakeEncryptor($keyId)])));
    }
}
