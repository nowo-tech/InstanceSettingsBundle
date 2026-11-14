<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Section;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\NotBlank;

final class SettingFieldTest extends TestCase
{
    public function testNamedConstructorsSetTypeAndDefaults(): void
    {
        self::assertSame(SettingType::String, SettingField::string('a', 'x')->type);
        self::assertSame(SettingType::Text, SettingField::text('a')->type);
        self::assertSame(SettingType::Email, SettingField::email('a', 'a@example.test')->type);
        self::assertSame(SettingType::Url, SettingField::url('a', 'https://example.test')->type);
        self::assertFalse(SettingField::bool('a')->default);
        self::assertSame(3, SettingField::int('a', 3)->default);
        self::assertSame(1.5, SettingField::float('a', 1.5)->default);
        self::assertSame('x', SettingField::choice('a', ['l' => 'x'], 'x')->default);

        $secret = SettingField::secret('token', 'label', 'help', true, [new NotBlank()], SettingType::Text);
        self::assertTrue($secret->secret);
        self::assertTrue($secret->portable);
        self::assertTrue($secret->required);
        self::assertSame(SettingType::Text, $secret->type);
        self::assertCount(1, $secret->constraints);
        self::assertNull($secret->default);
        self::assertFalse($secret->revealable, '1.15: a secret is not revealable unless the host says so');

        // 1.15: the opt-in travels with every copy of the field.
        $revealable = SettingField::secret('token', 'label', revealable: true);
        self::assertTrue($revealable->revealable);
        self::assertTrue($revealable->notPortable()->revealable);
        self::assertTrue($revealable->withGroup('creds')->revealable);
        self::assertTrue($revealable->withFormOptions(['attr' => []])->revealable);
        self::assertTrue($revealable->withVisibleWhen('provider', ['a'])->revealable);
    }

    public function testCopiesAndLabelKey(): void
    {
        $field = SettingField::string('site', 'x', null, 'help')->withFormOptions(['attr' => ['a' => 1]])->withFormOptions(['b' => 2]);
        self::assertSame(['b' => 2, 'attr' => ['a' => 1]], $field->formOptions);
        self::assertSame('site', $field->labelKey());
        self::assertSame('lbl', SettingField::string('site', label: 'lbl')->labelKey());

        $copy = $field->notPortable();
        self::assertFalse($copy->portable);
        self::assertSame($field->formOptions, $copy->formOptions);
        self::assertSame('help', $copy->help);
    }

    /**
     * @return iterable<string, array{0: callable(): SettingField, 1: string}>
     */
    public static function invalidFields(): iterable
    {
        yield 'name' => [static fn (): SettingField => SettingField::string('Bad-Name'), 'Invalid setting field name'];
        yield 'secret bool' => [static fn (): SettingField => new SettingField('a', SettingType::Bool, secret: true), 'only string'];
        yield 'secret default' => [static fn (): SettingField => new SettingField('a', SettingType::String, 'x', secret: true), 'cannot declare a default'];
        yield 'revealable plain' => [static fn (): SettingField => new SettingField('a', SettingType::String, revealable: true), 'only a secret field can be revealable'];
        yield 'choice empty' => [static fn (): SettingField => new SettingField('a', SettingType::Choice), 'at least one choice'];
        yield 'default type' => [static fn (): SettingField => new SettingField('a', SettingType::Int, 'x'), 'does not match the int type'];
        yield 'default choice' => [static fn (): SettingField => SettingField::choice('a', ['l' => 'x'], 'y'), 'does not match the choice type'];
    }

    /**
     * @param callable(): SettingField $factory
     */
    #[DataProvider('invalidFields')]
    public function testInvalidDefinitionsAreRefused(callable $factory, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $factory();
    }

    public function testMatchesType(): void
    {
        self::assertTrue(SettingField::matchesType(SettingType::Float, 2));
        self::assertTrue(SettingField::matchesType(SettingType::Float, 2.5));
        self::assertFalse(SettingField::matchesType(SettingType::Float, '2'));
        self::assertTrue(SettingField::matchesType(SettingType::Choice, 2, ['a' => 2]));
        self::assertFalse(SettingField::matchesType(SettingType::Choice, '2', ['a' => 2]));
        self::assertFalse(SettingField::matchesType(SettingType::Bool, 1));
        self::assertTrue(SettingType::Url->isString());
        self::assertFalse(SettingType::Choice->isString());
    }
}
