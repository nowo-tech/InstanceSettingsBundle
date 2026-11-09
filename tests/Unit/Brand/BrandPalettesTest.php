<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Brand;

use Nowo\InstanceSettingsBundle\Brand\BrandPalettes;
use Nowo\UiKitBundle\Appearance\Appearance;
use Nowo\UiKitBundle\Appearance\AppearancePalette;
use Nowo\UiKitBundle\Appearance\AppearancePreset;
use Nowo\UiKitBundle\Appearance\AppearancePresetRegistry;
use Nowo\UiKitBundle\Appearance\ConfigAppearanceProvider;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * Preset resolution of the appearance section (spec 220 §3.1).
 */
final class BrandPalettesTest extends TestCase
{
    public function testDefaultsAndPresetIds(): void
    {
        $palettes = new BrandPalettes();
        self::assertSame(['slate', 'midnight'], [$palettes->defaults()->themeId, $palettes->defaults()->themeIdDark]);
        self::assertContains('ocean', $palettes->presetIds());
        self::assertSame(Appearance::CUSTOM, $palettes->presetIds()[count($palettes->presetIds()) - 1]);
    }

    public function testCustomIsListedOnceWhenARegistryHasIt(): void
    {
        $custom   = new AppearancePreset('custom', 'light', $this->palette('#111111'), $this->palette('#222222'));
        $palettes = new BrandPalettes(null, new AppearancePresetRegistry([$custom]));

        self::assertCount(1, array_keys($palettes->presetIds(), 'custom', true));
    }

    public function testEmptyValuesEqualTheConfigProvider(): void
    {
        $registry = new AppearancePresetRegistry();
        $config   = new ConfigAppearanceProvider($registry, ['preset' => 'ember', 'preset_dark' => 'aurora']);
        $palettes = new BrandPalettes($config, $registry);

        self::assertEquals($config->getAppearance(), $palettes->appearance([], '', ''));
    }

    public function testUntouchedColoursFollowTheSelectedPreset(): void
    {
        $registry = new AppearancePresetRegistry();
        $palettes = new BrandPalettes(null, $registry);
        $ocean    = $registry->get('ocean');
        self::assertNotNull($ocean);

        $appearance = $palettes->appearance(['preset' => 'ocean', 'light_ink' => '#ABCDEF', 'dark_accent' => 'not-a-colour'], 'n', 'e');
        self::assertSame($ocean->light->accent, $appearance->light->accent);
        self::assertSame('#abcdef', $appearance->light->ink);
        self::assertSame(Appearance::CUSTOM, $appearance->themeId);
        self::assertSame('midnight', $appearance->themeIdDark, 'an invalid stored colour is ignored');

        $plain = $palettes->appearance(['preset' => 'ocean'], 'n', 'e');
        self::assertSame('ocean', $plain->themeId);
        self::assertSame($ocean->light->toArray(), $plain->light->toArray());
    }

    public function testCustomOrUnknownPresetsUseTheDefaultPalette(): void
    {
        $palettes = new BrandPalettes();
        $default  = $palettes->defaults();

        self::assertSame($default->light->toArray(), $palettes->presetPalette('light', 'custom')->toArray());
        self::assertSame($default->dark->toArray(), $palettes->presetPalette('dark', 'gone')->toArray());
        self::assertSame($default->light->toArray(), $palettes->presetPalette('light', null)->toArray());
        $appearance = $palettes->appearance(['preset' => 'custom', 'preset_dark' => ' '], 'n', 'e');
        self::assertSame([Appearance::CUSTOM, 'midnight'], [$appearance->themeId, $appearance->themeIdDark]);
    }

    public function testNonHexPresetColoursFallBackToTheKitDefaultsForTheContract(): void
    {
        $palette = new AppearancePalette('rgb(1 2 3)', '#ABCDEF', 'red', '#b54708', '#f1f5f9', '#0f172a', '#ffffff');

        $colors = BrandPalettes::colors($palette, 'light');
        self::assertSame('#475569', $colors->primary, 'slate accent');
        self::assertSame('#abcdef', $colors->primaryDeep);
        self::assertSame('#b42318', $colors->danger);
        self::assertSame('#60a5fa', BrandPalettes::colors(new AppearancePalette('x', '#000000', '#000000', '#000000', '#000000', '#000000', '#000000'), 'dark')->primary, 'midnight dark accent');
    }

    public function testStoredColor(): void
    {
        self::assertSame('#aabbcc', BrandPalettes::storedColor(' #AABBCC '));
        self::assertNull(BrandPalettes::storedColor('#abc'));
        self::assertNull(BrandPalettes::storedColor(12));
    }

    private function palette(string $accent): AppearancePalette
    {
        return new AppearancePalette($accent, $accent, $accent, $accent, '#ffffff', '#000000', '#ffffff');
    }
}
