<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Secret;

use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorInterface;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistry;
use Nowo\DoctrineEncryptBundle\Encryptors\MysqlAesEncryptor;
use Nowo\DoctrineEncryptBundle\Util\EncryptUtil;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use Nowo\InstanceSettingsBundle\Secret\DoctrineEncryptSecretSealer;
use Nowo\InstanceSettingsBundle\Tests\Support\FakeEncryptor;
use Nowo\InstanceSettingsBundle\Tests\Support\SealerFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class DoctrineEncryptSecretSealerTest extends TestCase
{
    private const CONTEXT = 'nowo-instance-settings|mailer.dsn';

    public function testRoundTripBoundToItsContext(): void
    {
        $sealer = SealerFactory::create();
        $sealed = $sealer->seal('REDACTED-TEST', self::CONTEXT);

        self::assertStringStartsWith('enc:v2:', $sealed);
        self::assertStringNotContainsString('REDACTED-TEST', $sealed);
        self::assertTrue($sealer->isSealed($sealed));
        self::assertFalse($sealer->isSealed('plain'));
        self::assertSame('REDACTED-TEST', $sealer->unseal($sealed, self::CONTEXT));
        self::assertSame('0', $sealer->unseal($sealer->seal('0', self::CONTEXT), self::CONTEXT), 'I7: "0" is a valid secret');
        self::assertSame('doctrine_encrypt:default', $sealer->algorithm());
        self::assertSame('doctrine_encrypt:p2', (new DoctrineEncryptSecretSealer(new EncryptUtil(new EncryptorRegistry([])), 'p2'))->algorithm());
    }

    public function testFailsClosed(): void
    {
        $sealer = SealerFactory::create();
        $cases  = [
            'invalid_sealed_secret' => [
                'plain',
                'enc:v1:' . base64_encode('x<ENC>'),
                'enc:v2:***',
                'enc:v2:' . base64_encode('no-marker'),
                'enc:v2:' . base64_encode(EncryptUtil::ENCRYPTION_MARKER),
            ],
            'failed_unseal_secret'    => [SealerFactory::create('k2')->seal('REDACTED-TEST', self::CONTEXT)],
            'secret_context_mismatch' => [
                $sealer->seal('REDACTED-TEST', 'nowo-instance-settings|sms.auth_token'),
                $sealer->seal('REDACTED-TEST', 'other-schema|mailer.dsn'),
            ],
        ];
        foreach ($cases as $reason => $values) {
            foreach ($values as $value) {
                try {
                    $sealer->unseal($value, self::CONTEXT);
                    self::fail('Expected ' . $reason);
                } catch (PortabilityException $e) {
                    self::assertSame($reason, $e->getMessage());
                }
            }
        }
    }

    public function testInvalidContextsAreRefused(): void
    {
        $sealer = SealerFactory::create();
        foreach (['seal_failed' => static fn (): string => $sealer->seal('x', "a\nb"), 'invalid_sealed_secret' => static fn (): string => $sealer->unseal('enc:v2:x', '')] as $reason => $call) {
            try {
                $call();
                self::fail('Expected ' . $reason);
            } catch (PortabilityException $e) {
                self::assertSame($reason, $e->getMessage());
            }
        }
    }

    public function testEncryptorsThatDoNotEncryptAreRefused(): void
    {
        // Returns its input: EncryptUtil marks it, but unsealing "decrypts" to the same bytes.
        $identity = new DoctrineEncryptSecretSealer(new EncryptUtil(new EncryptorRegistry(['default' => $this->encryptor(static fn (string $d): string => $d, static fn (string $d): string => $d)])));
        $sealed   = $identity->seal('REDACTED-TEST', self::CONTEXT);
        $this->expectFailure('failed_unseal_secret', static fn (): string => $identity->unseal($sealed, self::CONTEXT));

        // Decrypts to an empty payload or to the bare context.
        $empty = new DoctrineEncryptSecretSealer(new EncryptUtil(new EncryptorRegistry(['default' => $this->encryptor(static fn (string $d): string => 'c', static fn (string $d): string => '')])));
        $this->expectFailure('failed_unseal_secret', static fn (): string => $empty->unseal($empty->seal('x', self::CONTEXT), self::CONTEXT));
        $bare = new DoctrineEncryptSecretSealer(new EncryptUtil(new EncryptorRegistry(['default' => $this->encryptor(static fn (string $d): string => 'c', static fn (string $d): string => self::CONTEXT . "\n")])));
        $this->expectFailure('failed_unseal_secret', static fn (): string => $bare->unseal($bare->seal('x', self::CONTEXT), self::CONTEXT));

        // Throws.
        $throwing = new DoctrineEncryptSecretSealer(new EncryptUtil(new EncryptorRegistry(['default' => $this->encryptor(static fn (string $d): string => 'c', static fn (string $d): string => throw new RuntimeException('bad key'))])));
        $this->expectFailure('failed_unseal_secret', static fn (): string => $throwing->unseal($throwing->seal('x', self::CONTEXT), self::CONTEXT));
    }

    public function testCiphertextWithoutMarkerIsNotSealed(): void
    {
        $sealer = new DoctrineEncryptSecretSealer(new EncryptUtil(new EncryptorRegistry(['default' => $this->encryptor(static fn (string $d): string => '', static fn (string $d): string => $d)])));
        // EncryptUtil appends the marker to the encryptor output; an empty output still gets the marker only.
        $this->expectFailure('invalid_sealed_secret', static fn (): string => $sealer->unseal($sealer->seal('x', self::CONTEXT), self::CONTEXT));
    }

    public function testUnauthenticatedMysqlAesProfileIsRefused(): void
    {
        // Built without its constructor (which only emits a deprecation): the type is what matters.
        $mysqlAes = (new ReflectionClass(MysqlAesEncryptor::class))->newInstanceWithoutConstructor();
        $registry = new EncryptorRegistry(['default' => $mysqlAes, 'halite' => new FakeEncryptor()]);
        $sealer   = new DoctrineEncryptSecretSealer(new EncryptUtil($registry), null, $registry);

        $this->expectFailure('unsupported_encryptor', static fn (): string => $sealer->seal('x', self::CONTEXT));
        $this->expectFailure('unsupported_encryptor', static fn (): string => $sealer->unseal('enc:v2:x', self::CONTEXT));

        $named = new DoctrineEncryptSecretSealer(new EncryptUtil($registry), 'halite', $registry);
        self::assertSame('x', $named->unseal($named->seal('x', self::CONTEXT), self::CONTEXT));
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectFailure(string $reason, callable $call): void
    {
        try {
            $call();
            self::fail('Expected ' . $reason);
        } catch (PortabilityException $e) {
            self::assertSame($reason, $e->getMessage());
        }
    }

    /**
     * @param callable(string): string $encrypt
     * @param callable(string): string $decrypt
     */
    private function encryptor(callable $encrypt, callable $decrypt): EncryptorInterface
    {
        return new class($encrypt, $decrypt) implements EncryptorInterface {
            /** @var callable(string): string */
            private $encrypt;

            /** @var callable(string): string */
            private $decrypt;

            /**
             * @param callable(string): string $encrypt
             * @param callable(string): string $decrypt
             */
            public function __construct(callable $encrypt, callable $decrypt)
            {
                $this->encrypt = $encrypt;
                $this->decrypt = $decrypt;
            }

            public function encrypt(string $data): string
            {
                return ($this->encrypt)($data);
            }

            public function decrypt(string $data): string
            {
                return ($this->decrypt)($data);
            }
        };
    }
}
