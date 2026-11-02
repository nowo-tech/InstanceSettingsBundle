<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Section;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function print_r;
use function random_bytes;
use function var_export;

final class SettingFallbackTest extends TestCase
{
    public function testFactoriesAndMaskedDebugOutput(): void
    {
        $secret   = 'key-' . bin2hex(random_bytes(8));
        $fallback = SettingFallback::secret('api_key', $secret);
        self::assertTrue($fallback->secret);
        self::assertSame($secret, $fallback->value);
        self::assertStringNotContainsString($secret, print_r($fallback, true));
        self::assertSame(['field' => 'api_key', 'value' => '***', 'secret' => true], $fallback->__debugInfo());
        self::assertSame(['field' => 'api_key', 'value' => null, 'secret' => true], SettingFallback::secret('api_key', null)->__debugInfo());

        $plain = SettingFallback::plain('sender', 'Acme');
        self::assertFalse($plain->secret);
        self::assertSame(['field' => 'sender', 'value' => 'Acme', 'secret' => false], $plain->__debugInfo());
        self::assertStringContainsString('Acme', var_export($plain->__debugInfo(), true));
    }

    public function testInvalidFieldNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid setting fallback field name "Bad-Name".');
        SettingFallback::plain('Bad-Name', 'x');
    }
}
