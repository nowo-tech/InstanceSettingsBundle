<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\SectionTest;

use InvalidArgumentException;
use LogicException;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestContext;
use Nowo\InstanceSettingsBundle\SectionTest\SettingsSectionTesterInterface;
use PHPUnit\Framework\TestCase;

/**
 * 1.18: a tester reads the stored values through the context, and only while the test runs.
 */
final class SectionTestContextTest extends TestCase
{
    public function testReadsTheStoredValues(): void
    {
        $context = $this->context();

        self::assertSame('relay', $context->section);
        self::assertSame('smtp', $context->group);
        self::assertSame(['host', 'port', 'ratio', 'enabled', 'off', 'blank', 'none', 'token', 'empty'], $context->fields());
        self::assertSame(['host', 'token'], $context->groupFields());
        self::assertSame('smtp.example.test', $context->value('host'));
        self::assertSame('smtp.example.test', $context->string('host'));
        self::assertSame('587', $context->string('port'));
        self::assertSame('0.5', $context->string('ratio'));
        self::assertSame('1', $context->string('enabled'));
        self::assertNull($context->string('off'));
        self::assertNull($context->string('blank'));
        self::assertNull($context->string('none'));
        self::assertSame('tok-123456', $context->secret('token'));
        self::assertTrue($context->hasSecret('token'));
        self::assertNull($context->secret('empty'));
        self::assertFalse($context->hasSecret('empty'));
        self::assertSame(SettingsSectionTesterInterface::TIME_BUDGET_SECONDS, $context->timeBudgetSeconds());
        self::assertTrue($context->isOpen());
    }

    public function testAClosedContextNoLongerAnswers(): void
    {
        $context = $this->context();
        $context->close();

        self::assertFalse($context->isOpen());
        $this->expectException(LogicException::class);
        $context->secret('token');
    }

    public function testAnUnknownFieldIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"nope" is not a field');
        $this->context()->value('nope');
    }

    public function testASecretIsNotAPlainValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('use secret()');
        $this->context()->value('token');
    }

    public function testAPlainValueIsNotASecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('use value()');
        $this->context()->secret('host');
    }

    private function context(): SectionTestContext
    {
        $values = ['host' => 'smtp.example.test', 'port' => 587, 'ratio' => 0.5, 'enabled' => true, 'off' => false, 'blank' => '', 'none' => null, 'token' => 'tok-123456', 'empty' => ''];

        return new SectionTestContext(
            'relay',
            'smtp',
            ['host', 'port', 'ratio', 'enabled', 'off', 'blank', 'none', 'token', 'empty'],
            ['token', 'empty'],
            ['host', 'token'],
            static fn (string $field, bool $secret): bool|float|int|string|null => $values[$field],
        );
    }
}
