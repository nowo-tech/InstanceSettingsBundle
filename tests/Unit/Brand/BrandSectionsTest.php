<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Brand;

use Nowo\InstanceSettingsBundle\Brand\AppearanceSection;
use Nowo\InstanceSettingsBundle\Brand\BrandDefaults;
use Nowo\InstanceSettingsBundle\Brand\BrandFallbackProviderInterface;
use Nowo\InstanceSettingsBundle\Brand\BrandPalettes;
use Nowo\InstanceSettingsBundle\Brand\BrandSection;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Nowo\InstanceSettingsBundle\Tests\Support\BrandFixture;
use Nowo\UiKitBundle\Appearance\AppearancePresetRegistry;
use Nowo\UiKitBundle\Appearance\ConfigAppearanceProvider;
use Nowo\UiKitBundle\View\Label;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

use function array_map;
use function iterator_to_array;
use function strtoupper;

/**
 * The `brand` and `appearance` sections: fields, save filter, notices and fallbacks.
 */
final class BrandSectionsTest extends TestCase
{
    public function testBrandSectionFields(): void
    {
        $section = new BrandSection(new BrandDefaults('Acme', extraImages: [
            'mascot' => ['label' => 'app.mascot', 'help' => null, 'max_width' => 300, 'max_height' => 200, 'max_bytes' => 5000, 'square' => true],
            'email'  => ['label' => null, 'help' => 'app.email_help', 'max_width' => 600, 'max_height' => 200, 'max_bytes' => 9000, 'square' => false],
        ]));
        self::assertSame(['brand', 'brand.section.label', 'brand.section.intro', NowoInstanceSettingsBundle::TRANSLATION_DOMAIN], [$section->getName(), $section->getLabel(), $section->getIntro(), $section->getTranslationDomain()]);

        $fields = $this->byName($section->getFields());
        self::assertSame(['name', 'short_name', 'eyebrow', 'wordmark', 'logo_light', 'logo_dark', 'mark', 'app_icon', 'favicon', 'extra_mascot', 'extra_email'], array_keys($fields));
        self::assertSame(['Acme', true, 'identity'], [$fields['name']->default, $fields['name']->required, $fields['name']->group]);
        foreach (['logo_light', 'logo_dark', 'mark', 'app_icon', 'favicon', 'extra_mascot'] as $name) {
            self::assertSame(SettingType::Image, $fields[$name]->type);
            self::assertTrue($fields[$name]->image?->public, $name . ' is public');
            self::assertSame(['image/png', 'image/webp', 'image/jpeg'], $fields[$name]->image->types, 'no SVG upload');
            self::assertTrue($fields[$name]->formOptions['preview']);
            self::assertSame('images', $fields[$name]->group);
        }
        self::assertSame([2400, 800], [$fields['logo_light']->image?->maxWidth, $fields['logo_light']->image?->maxHeight]);
        self::assertSame([true, 64, 1024], [$fields['mark']->image?->square, $fields['mark']->image?->minWidth, $fields['mark']->image?->maxWidth]);
        self::assertSame([512, 192, 180], $fields['app_icon']->image?->variants);
        self::assertSame(512, $fields['app_icon']->image->minHeight);
        self::assertSame([32, 16], $fields['favicon']->image?->variants);
        self::assertSame(['app.mascot', 300, 200, 5000, true], [$fields['extra_mascot']->label, $fields['extra_mascot']->image?->maxWidth, $fields['extra_mascot']->image?->maxHeight, $fields['extra_mascot']->image?->maxBytes, $fields['extra_mascot']->image?->square]);
        self::assertSame(['brand.field.extra', 'app.email_help'], [$fields['extra_email']->label, $fields['extra_email']->help]);
    }

    public function testSlotFieldMapping(): void
    {
        self::assertSame('logo_dark', BrandSection::slotOf('logo_dark'));
        self::assertSame('extra.mascot', BrandSection::slotOf('extra_mascot'));
        self::assertNull(BrandSection::slotOf('name'));
        self::assertNull(BrandSection::slotOf('extra_'));
        self::assertSame('extra_mascot', BrandSection::fieldOf('extra.mascot'));
        self::assertSame('favicon', BrandSection::fieldOf('favicon'));
    }

    public function testTextConstraintsRefuseControlCharactersAndLength(): void
    {
        $fixture = new BrandFixture();
        foreach ([['name', "Evil\u{202E}x"], ['short_name', 'ThirteenChars'], ['eyebrow', "a\nb"]] as [$field, $value]) {
            try {
                $fixture->settings->save('brand', [$field => $value]);
                self::fail($field . ' accepted');
            } catch (InvalidSettingValueException $e) {
                self::assertArrayHasKey($field, $e->violations);
            }
        }
        self::assertSame(['name'], $fixture->settings->save('brand', ['name' => 'Café Größe']));
    }

    public function testAppearanceSectionFields(): void
    {
        $fixture = new BrandFixture();
        $section = $fixture->appearanceSection;
        self::assertSame(['appearance', 'appearance.section.label', 'appearance.section.intro'], [$section->getName(), $section->getLabel(), $section->getIntro()]);

        $fields = $this->byName($section->getFields());
        self::assertCount(2 + 14 + 3, $fields);
        self::assertSame(['slate', 'theme', false], [$fields['preset']->default, $fields['preset']->group, $fields['preset']->formOptions['choice_translation_domain']]);
        self::assertContains('custom', $fields['preset_dark']->choices);
        self::assertSame(['colors.light', null], [$fields['light_accent']->group, $fields['light_accent']->default]);
        self::assertSame('colors.dark', $fields['dark_surface']->group);
        self::assertSame(['soft', 'medium', false], [$fields['corner_style']->default, $fields['border_strength']->default, $fields['footer_fixed']->default]);
    }

    public function testAnUnknownConfiguredPresetIsStillAChoice(): void
    {
        $config   = new ConfigAppearanceProvider(new AppearancePresetRegistry([], [], ['ocean']), ['preset' => 'slate']);
        $palettes = new BrandPalettes($config, new AppearancePresetRegistry([], [], ['ocean']));
        $fields   = $this->byName((new BrandFixture(palettes: $palettes))->appearanceSection->getFields());

        self::assertContains('slate', $fields['preset']->choices);
    }

    public function testSaveLowerCasesAndDropsColoursEqualToThePreset(): void
    {
        $fixture = new BrandFixture();
        $ocean   = (new AppearancePresetRegistry())->get('ocean');
        self::assertNotNull($ocean);

        // Equal to the submitted preset's colour (any case): not stored.
        self::assertSame(['preset', 'light_ink'], $fixture->settings->save('appearance', [
            'preset'       => 'ocean',
            'light_accent' => strtoupper($ocean->light->accent),
            'light_ink'    => '#ABCDEF',
        ]));
        self::assertFalse($fixture->settings->isOverridden('appearance.light_accent'));
        self::assertSame('#abcdef', $fixture->settings->get('appearance.light_ink'));

        // Without the preset in the save, the stored preset decides.
        self::assertSame([], $fixture->settings->save('appearance', ['light_accent' => $ocean->light->accent]));
        // Changing the preset later reaches the untouched field.
        $fixture->settings->save('appearance', ['preset' => 'ember']);
        $ember = (new AppearancePresetRegistry())->get('ember');
        self::assertSame($ember?->light->accent, $fixture->provider->getBrand()->light->primary);

        // A value that is not a string is left to the validation; a dark colour equal to midnight is dropped.
        self::assertSame(['dark_accent' => null, 'dark_ink' => null, 'light_paper' => 7], $fixture->appearanceSection->filterValues(['dark_accent' => null, 'dark_ink' => '#E0E7FF', 'light_paper' => 7], []));
    }

    public function testInvalidColourIsRefused(): void
    {
        $fixture = new BrandFixture();
        $this->expectException(InvalidSettingValueException::class);
        $fixture->settings->save('appearance', ['dark_paper' => 'red']);
    }

    public function testContrastNoticesNameCodesOnly(): void
    {
        $fixture = new BrandFixture();
        $context = new SectionActionContext('appearance', new Request());
        self::assertSame([], iterator_to_array($this->list($fixture->appearanceSection->getNotices($context))));

        $fixture->settings->save('appearance', ['light_ink' => '#f0f0f0', 'dark_accent' => '#0f1524']);
        $keys = array_map(static fn (Label $label): string => $label->key, $this->list($fixture->appearanceSection->getNotices($context)));
        self::assertContains('appearance.contrast.light.text_on_paper', $keys);
        self::assertContains('appearance.contrast.light.text_on_surface', $keys);
        self::assertContains('appearance.contrast.dark.primary_on_surface', $keys);
        foreach ($keys as $key) {
            self::assertStringNotContainsString('#', $key);
        }
    }

    public function testNoticesAreEmptyWhenTheStoreFails(): void
    {
        $section = new AppearanceSection(new BrandPalettes(), (new BrandFixture(repository: new class implements SettingValueRepositoryInterface {
            public function findAll(): array
            {
                throw new RuntimeException('down');
            }

            public function findBySection(string $section): array
            {
                return [];
            }

            public function apply(array $save, array $remove): void
            {
            }

            public function transactional(callable $callback): mixed
            {
                return $callback();
            }
        }))->provider);

        // The provider falls back to the defaults (good contrast): no notice, no exception.
        self::assertSame([], $this->list($section->getNotices(new SectionActionContext('appearance', new Request()))));
    }

    public function testFallbacks(): void
    {
        $provider = new class implements BrandFallbackProviderInterface {
            public function getFallbacks(string $section): iterable
            {
                return $section === 'brand'
                    ? ['name' => 'Legacy', 'logo_light' => 'x.png', 'unknown' => 'y']
                    : ['preset' => 'ocean', 'light_accent' => '#0E7490', 'light_ink' => '#ABCDEF', 'corner_style' => 'sharp', 'footer_fixed' => false, 'dark_paper' => ''];
            }
        };
        $fixture = new BrandFixture(fallbacks: $provider);

        $brand = $this->fallbacks($fixture->brandSection->getFallbacks());
        self::assertSame(['name' => 'Legacy'], $brand);

        // Ocean's light accent is #0e7490: equal to the preset, so it is not seeded; the empty dark paper neither.
        self::assertSame(['preset' => 'ocean', 'light_ink' => '#abcdef', 'corner_style' => 'sharp', 'footer_fixed' => false], $this->fallbacks($fixture->appearanceSection->getFallbacks()));

        self::assertSame([], $this->list((new BrandSection(new BrandDefaults('A')))->getFallbacks()));
        self::assertSame([], $this->list((new BrandFixture())->appearanceSection->getFallbacks()));
    }

    /**
     * @param iterable<SettingField> $fields
     *
     * @return array<string, SettingField>
     */
    private function byName(iterable $fields): array
    {
        $map = [];
        foreach ($fields as $field) {
            $map[$field->name] = $field;
        }

        return $map;
    }

    /**
     * @param iterable<SettingFallback> $fallbacks
     *
     * @return array<string, bool|float|int|string|null>
     */
    private function fallbacks(iterable $fallbacks): array
    {
        $map = [];
        foreach ($fallbacks as $fallback) {
            $map[$fallback->field] = $fallback->value();
        }

        return $map;
    }

    /**
     * @template T
     *
     * @param iterable<T> $items
     *
     * @return list<T>
     */
    private function list(iterable $items): array
    {
        $list = [];
        foreach ($items as $item) {
            $list[] = $item;
        }

        return $list;
    }
}
