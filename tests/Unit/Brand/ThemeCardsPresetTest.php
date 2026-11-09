<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Brand;

use Nowo\InstanceSettingsBundle\Brand\AppearanceSection;
use Nowo\InstanceSettingsBundle\Brand\BrandPalettes;
use Nowo\InstanceSettingsBundle\Form\ThemeCardsType;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Tests\Support\BrandFixture;
use Nowo\UiKitBundle\Appearance\AppearancePalette;
use Nowo\UiKitBundle\Appearance\AppearancePreset;
use Nowo\UiKitBundle\Appearance\AppearancePresetRegistry;
use Nowo\UiKitBundle\Appearance\BuiltInAppearancePresets;
use PHPUnit\Framework\TestCase;

/**
 * IS-2 (1.6): `brand.preset_picker: cards` turns the two preset fields into theme cards with UiKit's preset names and
 * a `#rrggbb` swatch of the preset palette of the field's mode; the default keeps the plain select.
 */
final class ThemeCardsPresetTest extends TestCase
{
    public function testTheDefaultKeepsThePlainChoice(): void
    {
        $fields = $this->fields((new BrandFixture())->appearanceSection);

        self::assertFalse($fields['preset']->hasThemeCards());
        self::assertFalse($fields['preset']->formOptions['choice_translation_domain']);
        self::assertSame('ocean', $fields['preset']->choices['ocean']);
    }

    public function testCardsUseUiKitNamesAndModeSwatches(): void
    {
        $fixture = new BrandFixture();
        $section = new AppearanceSection($fixture->palettes, $fixture->provider, null, true);
        $fields  = $this->fields($section);

        foreach (['preset' => 'light', 'preset_dark' => 'dark'] as $name => $mode) {
            $field = $fields[$name];
            self::assertTrue($field->hasThemeCards());
            self::assertSame(['theme', AppearanceSection::UI_KIT_DOMAIN, true], [$field->group, $field->formOptions['choice_translation_domain'], $field->required]);
            self::assertSame('ocean', $field->choices['appearance.preset.ocean']);
            self::assertSame('custom', $field->choices['appearance.preset.custom']);
            $ocean = BuiltInAppearancePresets::get('ocean')->palette($mode)->toArray();
            self::assertSame([$ocean['paper'], $ocean['surface'], $ocean['ink'], $ocean['accent'], $ocean['accent_deep']], $field->formOptions[ThemeCardsType::SWATCHES]['ocean']);
            self::assertSame([], $field->formOptions[ThemeCardsType::SWATCHES]['custom'], 'custom has no palette of its own');
        }
        self::assertSame('slate', $fields['preset']->default);
        self::assertSame('midnight', $fields['preset_dark']->default);
    }

    public function testHostPresetsGetAReadableNameAndHexOnlySwatches(): void
    {
        $odd      = AppearancePalette::fromArray(['accent' => 'rgb(1 2 3)', 'paper' => '#FAFAFA']);
        $registry = new AppearancePresetRegistry([new AppearancePreset('harbor-blue_two', 'light', $odd, $odd)]);
        $palettes = new BrandPalettes(null, $registry);
        $fixture  = new BrandFixture(palettes: $palettes);
        $fields   = $this->fields(new AppearanceSection($palettes, $fixture->provider, null, true));

        self::assertSame('harbor-blue_two', $fields['preset']->choices['Harbor blue two']);
        $swatch = $fields['preset']->formOptions[ThemeCardsType::SWATCHES]['harbor-blue_two'];
        self::assertSame('#fafafa', $swatch[0]);
        self::assertSame(BuiltInAppearancePresets::get('slate')->light->accent, $swatch[3], 'a non-hex preset colour falls back to the kit default');
        self::assertSame([], $palettes->swatch('light', 'nope'));
        self::assertSame(strtolower(BuiltInAppearancePresets::get('midnight')->dark->accent), $palettes->swatch('dark', 'harbor-blue_two')[3]);
    }

    public function testHostPresetsWithTheSameReadableNameAreAllKept(): void
    {
        $palette  = AppearancePalette::fromArray(['paper' => '#ffffff']);
        $registry = new AppearancePresetRegistry([new AppearancePreset('harbor-blue', 'light', $palette, $palette), new AppearancePreset('harbor_blue', 'light', $palette, $palette)]);
        $palettes = new BrandPalettes(null, $registry);
        $fixture  = new BrandFixture(palettes: $palettes);
        $choices  = $this->fields(new AppearanceSection($palettes, $fixture->provider, null, true))['preset']->choices;

        self::assertSame('harbor-blue', $choices['Harbor blue']);
        self::assertSame('harbor_blue', $choices['harbor_blue'], 'a label clash falls back to the raw id');
    }

    /**
     * @return array<string, SettingField>
     */
    private function fields(AppearanceSection $section): array
    {
        $fields = [];
        foreach ($section->getFields() as $field) {
            $fields[$field->name] = $field;
        }

        return $fields;
    }
}
