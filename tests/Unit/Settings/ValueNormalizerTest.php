<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Validation;

final class ValueNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: SettingField, 1: mixed, 2: mixed, 3: int}>
     */
    public static function cases(): iterable
    {
        yield 'string trimmed' => [SettingField::string('a'), '  x  ', 'x', 0];
        yield 'blank string is null' => [SettingField::string('a'), '   ', null, 0];
        yield 'required blank' => [SettingField::string('a', required: true), '', null, 1];
        yield 'text keeps spaces' => [SettingField::text('a'), " x\n", " x\n", 0];
        yield 'bool from string' => [SettingField::bool('a'), 'on', true, 0];
        yield 'bool from int' => [SettingField::bool('a'), 0, false, 0];
        yield 'bool invalid' => [SettingField::bool('a'), 'maybe', null, 1];
        yield 'bool native' => [SettingField::bool('a'), true, true, 0];
        yield 'bool array' => [SettingField::bool('a'), [], null, 1];
        yield 'int from string' => [SettingField::int('a'), '-12', -12, 0];
        yield 'int invalid' => [SettingField::int('a'), '1.5', null, 1];
        yield 'int range' => [SettingField::int('a', constraints: [new Range(min: 1, max: 5)]), 9, 9, 1];
        yield 'float from string' => [SettingField::float('a'), '2.5', 2.5, 0];
        yield 'float from int' => [SettingField::float('a'), 2, 2.0, 0];
        yield 'float invalid' => [SettingField::float('a'), 'x', null, 1];
        yield 'email ok' => [SettingField::email('a'), 'a@example.test', 'a@example.test', 0];
        yield 'email bad' => [SettingField::email('a'), 'nope', 'nope', 1];
        yield 'url ok' => [SettingField::url('a'), 'https://example.test/x', 'https://example.test/x', 0];
        yield 'url scheme' => [SettingField::url('a'), 'ftp://example.test', null, 1];
        yield 'choice ok' => [SettingField::choice('a', ['l' => 'x', 'm' => 'y']), 'y', 'y', 0];
        yield 'choice bad' => [SettingField::choice('a', ['l' => 'x']), 'z', null, 1];
        yield 'string array' => [SettingField::string('a'), ['x'], null, 1];
    }

    #[DataProvider('cases')]
    public function testNormalize(SettingField $field, mixed $input, mixed $expected, int $violations): void
    {
        [$value, $messages] = (new ValueNormalizer(Validation::createValidator()))->normalize($field, $input);

        self::assertCount($violations, $messages);
        if ($violations === 0 || $value !== null) {
            self::assertSame($expected, $value);
        }
    }

    public function testWithoutValidatorOnlyTypesAreChecked(): void
    {
        [$value, $messages] = (new ValueNormalizer())->normalize(SettingField::email('a'), 'nope');
        self::assertSame('nope', $value);
        self::assertSame([], $messages);

        [, $messages] = (new ValueNormalizer())->normalize(new SettingField('a', SettingType::Choice, choices: ['x' => 1]), '1');
        self::assertSame(['The value is not one of the allowed choices.'], $messages);
    }
}
