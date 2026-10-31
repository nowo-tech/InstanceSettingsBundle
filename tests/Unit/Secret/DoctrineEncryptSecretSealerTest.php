<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Secret;

use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistry;
use Nowo\DoctrineEncryptBundle\Util\EncryptUtil;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use Nowo\InstanceSettingsBundle\Secret\DoctrineEncryptSecretSealer;
use Nowo\InstanceSettingsBundle\Tests\Support\SealerFactory;
use PHPUnit\Framework\TestCase;

final class DoctrineEncryptSecretSealerTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $sealer = SealerFactory::create();
        $sealed = $sealer->seal('REDACTED-TEST');

        self::assertStringStartsWith('enc:v1:', $sealed);
        self::assertStringNotContainsString('REDACTED-TEST', $sealed);
        self::assertTrue($sealer->isSealed($sealed));
        self::assertFalse($sealer->isSealed('plain'));
        self::assertSame('REDACTED-TEST', $sealer->unseal($sealed));
        self::assertSame('doctrine_encrypt:default', $sealer->algorithm());
        self::assertSame('doctrine_encrypt:p2', (new DoctrineEncryptSecretSealer(new EncryptUtil(new EncryptorRegistry([])), 'p2'))->algorithm());
    }

    public function testFailsClosed(): void
    {
        $other = SealerFactory::create('k2')->seal('REDACTED-TEST');
        $cases = [
            'invalid_sealed_secret' => ['plain', 'enc:v1:***', 'enc:v1:' . base64_encode('no-marker'), 'enc:v1:' . base64_encode(EncryptUtil::ENCRYPTION_MARKER)],
            'failed_unseal_secret'  => [$other],
        ];
        foreach ($cases as $reason => $values) {
            foreach ($values as $value) {
                try {
                    SealerFactory::create()->unseal($value);
                    self::fail('Expected ' . $reason);
                } catch (PortabilityException $e) {
                    self::assertSame($reason, $e->getMessage());
                }
            }
        }
    }

    public function testEmptyDecryptAndSealFailures(): void
    {
        $empty = new class implements EncryptorInterface {
            public function encrypt(string $data): string
            {
                return $data;
            }

            public function decrypt(string $data): string
            {
                return '';
            }
        };
        $sealer = new DoctrineEncryptSecretSealer(new EncryptUtil(new EncryptorRegistry(['default' => $empty])));

        try {
            $sealer->unseal('enc:v1:' . base64_encode('x' . EncryptUtil::ENCRYPTION_MARKER));
            self::fail('Expected failed_unseal_secret');
        } catch (PortabilityException $e) {
            self::assertSame('failed_unseal_secret', $e->getMessage());
        }

        foreach (['', '0'] as $plain) {
            try {
                $sealer->seal($plain);
                self::fail('Expected seal_failed');
            } catch (PortabilityException $e) {
                self::assertSame('seal_failed', $e->getMessage());
            }
        }
    }
}
