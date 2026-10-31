<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use PHPUnit\Framework\TestCase;

final class SettingValueTest extends TestCase
{
    public function testValueAndSecretAreExclusive(): void
    {
        $created = new DateTimeImmutable('2026-01-01');
        $row     = new SettingValue('mailer', 'from', $created);
        self::assertNull($row->getId());
        self::assertSame(7, $row->setId(7)->getId());
        self::assertSame('mailer', $row->getSection());
        self::assertSame('from', $row->getName());
        self::assertSame($created, $row->getUpdatedAt());

        $later = new DateTimeImmutable('2026-01-02');
        $row->storeSecret('REDACTED-TEST', $later);
        self::assertSame('REDACTED-TEST', $row->getSecret());
        self::assertSame('REDACTED-TEST|', $row->getSecretValue(), 'stored with its terminator (never ends with <ENC>, never "0")');
        self::assertNull($row->getValue());
        self::assertSame($later, $row->getUpdatedAt());

        $row->storeValue(true, $created);
        self::assertTrue($row->getValue());
        self::assertNull($row->getSecretValue());

        self::assertNull($row->getSecret());

        // A value DoctrineEncrypt could not decrypt (no terminator) is no secret.
        $row->setSecretValue('def502-ciphertext');
        self::assertSame('def502-ciphertext', $row->getSecretValue());
        self::assertNull($row->getSecret());
        $row->storeSecret('a<ENC>', $created);
        self::assertSame('a<ENC>', $row->getSecret());
    }
}
