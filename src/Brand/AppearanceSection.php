<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Brand;

use Nowo\Brand\Contract\ColorMath;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SectionFallbackProviderInterface;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionNoticesInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionSaveFilterInterface;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Nowo\UiKitBundle\Appearance\Appearance;
use Nowo\UiKitBundle\Appearance\AppearancePreset;
use Nowo\UiKitBundle\Appearance\BorderStrength;
use Nowo\UiKitBundle\Appearance\BuiltInAppearancePresets;
use Nowo\UiKitBundle\Appearance\CornerStyle;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Validator\Constraints\Regex;

use function array_combine;
use function array_key_exists;
use function in_array;
use function is_string;
use function preg_match;
use function str_replace;
use function strtolower;
use function trim;
use function ucfirst;

/**
 * The `appearance` settings section of the brand bridge (spec 220 §3.1, §6): the preset per mode, seven colours per
 * mode, corner style, border strength and fixed footer.
 *
 * Colours are `#rrggbb` only (lower-cased on save; 1.12: {@see SettingType::Color} fields, a swatch paired with the hex
 * input, following the selected preset). An empty colour follows the selected preset, and a saved colour equal to it is
 * dropped (not stored), so changing the preset later still reaches untouched fields. Contrast below
 * WCAG AA is a notice, never a refusal (owner decision Q-O8).
 */
final class AppearanceSection extends AbstractSettingsSection implements SettingsSectionSaveFilterInterface, SettingsSectionNoticesInterface, SectionFallbackProviderInterface
{
    /** Section name. */
    public const NAME = 'appearance';

    /** Accepted stored colour. */
    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /** Translation domain of UiKit's preset names (theme cards, 1.6). */
    public const UI_KIT_DOMAIN = 'NowoUiKitBundle';

    /** Modes and their field prefixes. */
    private const MODES = [AppearancePreset::MODE_LIGHT => 'light_', AppearancePreset::MODE_DARK => 'dark_'];

    /**
     * @param BrandPalettes $palettes UiKit presets and defaults
     * @param InstanceBrandProvider $brand effective colours of the contrast notices
     * @param BrandFallbackProviderInterface|null $fallbacks host values to seed from (spec 220 §7.1)
     * @param bool $presetCards `brand.preset_picker: cards` (1.6, IS-2): the two preset fields as theme cards with
     *                          swatches and UiKit's preset names, instead of a select of ids
     */
    public function __construct(
        private readonly BrandPalettes $palettes,
        private readonly InstanceBrandProvider $brand,
        private readonly ?BrandFallbackProviderInterface $fallbacks = null,
        private readonly bool $presetCards = false,
    ) {
    }

    /**
     * `appearance`.
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * `appearance.section.label` in the bundle domain.
     */
    public function getLabel(): string
    {
        return 'appearance.section.label';
    }

    /**
     * The bundle domain.
     */
    public function getTranslationDomain(): string
    {
        return NowoInstanceSettingsBundle::TRANSLATION_DOMAIN;
    }

    /**
     * `appearance.section.intro`.
     */
    public function getIntro(): string
    {
        return 'appearance.section.intro';
    }

    /**
     * Presets, the 2 × 7 colours, corner style, border strength and fixed footer.
     *
     * @return list<SettingField>
     */
    public function getFields(): iterable
    {
        $defaults = $this->palettes->defaults();
        $ids      = $this->palettes->presetIds();
        $choices  = array_combine($ids, $ids);
        $fields   = [
            $this->preset('preset', AppearancePreset::MODE_LIGHT, $choices, $defaults->themeId),
            $this->preset('preset_dark', AppearancePreset::MODE_DARK, $choices, $defaults->themeIdDark),
        ];
        foreach (self::MODES as $mode => $prefix) {
            // 1.12: colour inputs (swatch + hex + "Use the preset colour"); the swatch of an empty colour follows the
            // preset selected for the mode, live.
            $presetField = $fields[$mode === AppearancePreset::MODE_DARK ? 1 : 0];
            $palettes    = [];
            foreach ($presetField->choices as $id) {
                $palettes[(string) $id] = $this->palettes->presetPalette($mode, (string) $id)->toArray();
            }
            foreach (BrandPalettes::KEYS as $key) {
                $presets = [];
                foreach ($palettes as $id => $palette) {
                    $color = $palette[$key] ?? null;
                    if (is_string($color) && preg_match(self::COLOR_PATTERN, $color) === 1) {
                        $presets[$id] = $color;
                    }
                }
                $field = SettingField::color(
                    $prefix . $key,
                    label: 'appearance.field.' . $prefix . $key,
                    help: 'appearance.help.color',
                    constraints: [new Regex(pattern: self::COLOR_PATTERN, message: 'This value is not a #rrggbb colour.')],
                );
                $fields[] = ($presets === [] ? $field : $field->withPresetFrom($presetField->name, $presets))->withGroup('colors.' . $mode);
            }
        }
        $corners  = CornerStyle::values();
        $borders  = BorderStrength::values();
        $fields[] = $this->choice('corner_style', array_combine($corners, $corners), $defaults->cornerStyle, 'layout');
        $fields[] = $this->choice('border_strength', array_combine($borders, $borders), $defaults->borderStrength, 'layout');
        $fields[] = (new SettingField('footer_fixed', SettingType::Bool, $defaults->footerFixed, 'appearance.field.footer_fixed', 'appearance.help.footer_fixed'))->withGroup('layout');

        return $fields;
    }

    /**
     * Lower-cases the submitted colours and drops (null = not stored) every colour equal to the preset selected for
     * its mode (the submitted preset, else the current one).
     */
    public function filterValues(array $values, array $current): array
    {
        foreach (self::MODES as $mode => $prefix) {
            $presetField = $mode === AppearancePreset::MODE_DARK ? 'preset_dark' : 'preset';
            $selected    = array_key_exists($presetField, $values) ? [$presetField => $values[$presetField]] : [$presetField => $current[$presetField] ?? null];
            $base        = $this->palettes->presetPalette($mode, $this->palettes->presetId($mode, $selected))->toArray();
            foreach (BrandPalettes::KEYS as $key) {
                $name = $prefix . $key;
                if (!array_key_exists($name, $values) || !is_string($values[$name])) {
                    continue;
                }
                $color         = strtolower(trim($values[$name]));
                $values[$name] = $color === strtolower($base[$key]) ? null : $color;
            }
        }

        return $values;
    }

    /**
     * Advisory WCAG contrast notices of the effective colours (codes only, never a stored value).
     *
     * @return list<Label>
     */
    public function getNotices(SectionActionContext $context): iterable
    {
        // The provider never throws on a store failure (it falls back to the defaults).
        $brand   = $this->brand->getBrand();
        $notices = [];
        foreach ([AppearancePreset::MODE_LIGHT => $brand->light, AppearancePreset::MODE_DARK => $brand->dark] as $mode => $colors) {
            foreach (ColorMath::contrastWarnings($colors) as $code) {
                $notices[] = new Label('appearance.contrast.' . $mode . '.' . $code, NowoInstanceSettingsBundle::TRANSLATION_DOMAIN);
            }
        }

        return $notices;
    }

    /**
     * The host fallback provider's `appearance` values, filtered like a save (colours lower-cased, a colour equal to
     * the preset dropped). Unknown keys are reported by the seeder.
     *
     * @return iterable<SettingFallback>
     */
    public function getFallbacks(): iterable
    {
        if (!$this->fallbacks instanceof BrandFallbackProviderInterface) {
            return [];
        }
        $values = [];
        foreach ($this->fallbacks->getFallbacks(self::NAME) as $field => $value) {
            if (is_string($field)) {
                $values[$field] = $value;
            }
        }
        $list = [];
        foreach ($this->filterValues($values, []) as $field => $value) {
            if ($value !== null && (!is_string($value) || $value !== '')) {
                $list[] = SettingFallback::plain($field, $value);
            }
        }

        return $list;
    }

    /**
     * A preset field: the plain choice of ids, or (`$presetCards`) theme cards labelled with UiKit's preset names
     * (`appearance.preset.<id>` in the NowoUiKitBundle domain for built-ins and `custom`, a readable id for host
     * presets, as UiKit's AppearanceType) with a swatch of the preset palette of `$mode`.
     *
     * @param array<string, string> $choices
     */
    private function preset(string $name, string $mode, array $choices, string $default): SettingField
    {
        $field = $this->choice($name, $choices, $default, 'theme');
        if (!$this->presetCards) {
            return $field;
        }

        $labelled = [];
        $swatches = [];
        foreach ($field->choices as $id) {
            $id    = (string) $id;
            $known = $id === Appearance::CUSTOM || in_array($id, BuiltInAppearancePresets::IDS, true);
            $label = $known ? 'appearance.preset.' . $id : ucfirst(str_replace(['-', '_'], ' ', $id));
            // Labels are the choice keys: two host ids with the same readable label ("my-theme", "my_theme") keep
            // their raw ids, so no preset is ever dropped from the picker.
            $label            = array_key_exists($label, $labelled) || $label === '' ? $id : $label;
            $labelled[$label] = $id;
            $swatches[$id]    = $this->palettes->swatch($mode, $id);
        }

        return (new SettingField($name, SettingType::Choice, $default, 'appearance.field.' . $name, 'appearance.help.' . $name, true, choices: $labelled))
            ->withFormOptions(['choice_translation_domain' => self::UI_KIT_DOMAIN])
            ->withThemeCards($swatches)
            ->withGroup('theme');
    }

    /**
     * @param array<string, string> $choices
     */
    private function choice(string $name, array $choices, string $default, string $group): SettingField
    {
        if (!in_array($default, $choices, true)) {
            $choices[$default] = $default;
        }

        return (new SettingField($name, SettingType::Choice, $default, 'appearance.field.' . $name, 'appearance.help.' . $name, true, choices: $choices))
            ->withFormOptions(['choice_translation_domain' => false])
            ->withGroup($group);
    }
}
