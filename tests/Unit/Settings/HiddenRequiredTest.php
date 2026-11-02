<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_key_last;
use function array_reverse;

/**
 * 1.3 audit N1: `required` on a provider-dependent field is decided on the NORMALIZED controlling value (merged over
 * the stored ones), whatever the key order: a padded / loosely typed controller cannot hide a required field.
 */
final class HiddenRequiredTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function visibleControllers(): iterable
    {
        yield 'padded string' => [['provider' => ' smtp ', 'host' => null]];
        yield 'bool "true"' => [['flag' => 'true', 'flag_target' => null]];
        yield 'bool "yes"' => [['flag' => 'yes', 'flag_target' => null]];
        yield 'int "01"' => [['level' => '01', 'level_target' => null]];
        yield 'int " 1 "' => [['level' => ' 1 ', 'level_target' => null]];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('visibleControllers')]
    public function testRequiredIsEnforcedWhenTheNormalizedControllerShowsTheField(array $values): void
    {
        foreach ([$values, array_reverse($values, true)] as $ordered) {
            try {
                $this->settings()->save('x', $ordered);
                self::fail('required field accepted empty');
            } catch (InvalidSettingValueException $e) {
                self::assertSame(['This value should not be blank.'], $e->violations[array_key_last($values)]);
            }
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function hiddenControllers(): iterable
    {
        yield 'other provider' => [['provider' => ' api ', 'host' => null]];
        yield 'bool "no"' => [['flag' => 'no', 'flag_target' => null]];
        yield 'int "02"' => [['level' => '02', 'level_target' => null]];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('hiddenControllers')]
    public function testRequiredIsSkippedWhenTheNormalizedControllerHidesTheField(array $values): void
    {
        foreach ([$values, array_reverse($values, true)] as $ordered) {
            $this->settings()->save('x', $ordered);
            $this->addToAssertionCount(1);
        }
    }

    public function testStoredControllerDecidesWhenNotSaved(): void
    {
        $settings = $this->settings();
        $settings->save('x', ['provider' => 'smtp', 'host' => 'mail.example.test']);

        $this->expectException(InvalidSettingValueException::class);
        $settings->save('x', ['host' => null]);
    }

    private function settings(): InstanceSettings
    {
        $section = new class extends AbstractSettingsSection {
            public function getName(): string
            {
                return 'x';
            }

            public function getFields(): iterable
            {
                yield SettingField::choice('provider', ['a' => 'smtp', 'b' => 'api']);
                yield SettingField::string('host', required: true)->withVisibleWhen('provider', ['smtp']);
                yield SettingField::bool('flag');
                yield SettingField::string('flag_target', required: true)->withVisibleWhen('flag', [true]);
                yield SettingField::int('level');
                yield SettingField::string('level_target', required: true)->withVisibleWhen('level', ['1']);
            }
        };

        return new InstanceSettings(new InMemorySettingValueRepository(), new SettingsSectionRegistry([$section]));
    }
}
