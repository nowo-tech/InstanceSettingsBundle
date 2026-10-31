<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Exception;

use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use PHPUnit\Framework\TestCase;

final class ExceptionsTest extends TestCase
{
    public function testMessages(): void
    {
        self::assertSame('Unknown instance settings section "x".', UnknownSettingException::section('x')->getMessage());
        self::assertSame('Unknown instance setting "x.y".', UnknownSettingException::field('x', 'y')->getMessage());
        self::assertStringContainsString('"<section>.<field>"', UnknownSettingException::malformedKey('x')->getMessage());

        $invalid = new InvalidSettingValueException('mailer', ['from' => ['Bad.', 'Worse.'], 'dsn' => ['No.']]);
        self::assertSame('Invalid instance settings: mailer.from: Bad. Worse.; mailer.dsn: No.', $invalid->getMessage());
        self::assertSame('mailer', $invalid->section);

        $portability = new PortabilityException('invalid_value:mailer.from');
        self::assertSame('invalid_value', $portability->reason());
        self::assertSame('mailer.from', $portability->key());
        self::assertNull((new PortabilityException('too_large'))->key());
    }
}
