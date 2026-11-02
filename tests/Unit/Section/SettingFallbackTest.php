<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Section;

use InvalidArgumentException;
use LogicException;
use Nowo\InstanceSettingsBundle\Section\FallbackValue;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function json_encode;
use function print_r;
use function random_bytes;
use function serialize;
use function var_export;

final class SettingFallbackTest extends TestCase
{
    public function testFactoriesAndMaskedDebugOutput(): void
    {
        $secret   = 'key-' . bin2hex(random_bytes(8));
        $fallback = SettingFallback::secret('api_key', $secret);
        self::assertTrue($fallback->secret);
        self::assertSame($secret, $fallback->value());
        self::assertStringNotContainsString($secret, var_export($fallback, true));
        self::assertStringNotContainsString($secret, (string) json_encode($fallback));
        self::assertStringNotContainsString($secret, print_r((array) $fallback, true));
        self::assertSame('{"field":"api_key","value":"***","secret":true}', json_encode($fallback));
        self::assertStringNotContainsString($secret, print_r($fallback, true));
        self::assertSame(['field' => 'api_key', 'value' => '***', 'secret' => true], $fallback->__debugInfo());
        self::assertSame(['field' => 'api_key', 'value' => null, 'secret' => true], SettingFallback::secret('api_key', null)->__debugInfo());

        $plain = SettingFallback::plain('sender', 'Acme');
        self::assertFalse($plain->secret);
        self::assertSame(['field' => 'sender', 'value' => 'Acme', 'secret' => false], $plain->__debugInfo());
        self::assertStringContainsString('Acme', var_export($plain->__debugInfo(), true));
    }

    public function testSerializationIsRefused(): void
    {
        $this->expectException(LogicException::class);
        serialize(SettingFallback::secret('api_key', 'x' . bin2hex(random_bytes(4))));
    }

    public function testTheHolderIsOpaque(): void
    {
        $secret = 'key-' . bin2hex(random_bytes(8));
        $holder = new FallbackValue($secret);
        self::assertSame($secret, $holder->get());
        self::assertSame([], $holder->__debugInfo());
        self::assertStringNotContainsString($secret, print_r($holder, true) . var_export($holder, true) . json_encode($holder));

        $this->expectException(LogicException::class);
        serialize($holder);
    }

    public function testInvalidFieldNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid setting fallback field name "Bad-Name".');
        SettingFallback::plain('Bad-Name', 'x');
    }
}
