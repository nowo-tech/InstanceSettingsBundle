<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Brand;

use Nowo\Brand\Contract\BrandColors;
use Nowo\Brand\Contract\ColorMath;
use Nowo\UiKitBundle\Appearance\Appearance;
use Nowo\UiKitBundle\Appearance\AppearancePalette;
use Nowo\UiKitBundle\Appearance\AppearancePreset;
use Nowo\UiKitBundle\Appearance\AppearancePresetRegistry;
use Nowo\UiKitBundle\Appearance\BorderStrength;
use Nowo\UiKitBundle\Appearance\BuiltInAppearancePresets;
use Nowo\UiKitBundle\Appearance\ConfigAppearanceProvider;
use Nowo\UiKitBundle\Appearance\CornerStyle;

use function in_array;
use function is_bool;
use function is_string;
use function strtolower;
use function trim;

/**
 * Resolves the stored values of the `appearance` section against UiKit's presets and defaults (spec 220 §3.1).
 *
 * Every colour that is not stored follows the preset selected for its mode (the stored `preset` / `preset_dark`, else
 * `nowo_ui_kit.appearance.defaults.preset` / `preset_dark`), read from UiKit's preset registry at runtime, so changing
 * the preset later still reaches untouched fields. Without UiKit's appearance (`appearance.enabled: false`) the
 * built-in presets and the kit defaults (`slate` / `midnight`) apply.
 *
 * Stateless (worker-safe): the registry and the defaults provider are immutable services.
 */
final readonly class BrandPalettes
{
    /** UiKit palette keys, in order ({@see AppearancePalette::KEYS}); storage keys are `light_<key>` / `dark_<key>`. */
    public const KEYS = AppearancePalette::KEYS;

    /** Contract colour name of each UiKit palette key (spec 220 §3.1). */
    public const CONTRACT_KEYS = [
        'accent'      => 'primary',
        'accent_deep' => 'primaryDeep',
        'danger'      => 'danger',
        'warn'        => 'warning',
        'paper'       => 'paper',
        'ink'         => 'text',
        'surface'     => 'surface',
    ];

    private ConfigAppearanceProvider $defaults;

    private AppearancePresetRegistry $registry;

    /**
     * @param ConfigAppearanceProvider|null $defaults UiKit's private `nowo_ui_kit.appearance.defaults_provider` (null
     *                                                while UiKit's appearance is disabled: the kit defaults)
     * @param AppearancePresetRegistry|null $registry UiKit's preset registry (null: the built-in presets)
     */
    public function __construct(?ConfigAppearanceProvider $defaults = null, ?AppearancePresetRegistry $registry = null)
    {
        $this->registry = $registry ?? new AppearancePresetRegistry();
        $this->defaults = $defaults ?? new ConfigAppearanceProvider($this->registry);
    }

    /**
     * UiKit's configured appearance defaults (preset ids, palettes, corner, border, footer, name, eyebrow).
     */
    public function defaults(): Appearance
    {
        return $this->defaults->getDefaults();
    }

    /**
     * Preset ids the `preset` / `preset_dark` fields offer, plus {@see Appearance::CUSTOM}.
     *
     * @return list<string>
     */
    public function presetIds(): array
    {
        $ids = $this->registry->ids();
        if (!in_array(Appearance::CUSTOM, $ids, true)) {
            $ids[] = Appearance::CUSTOM;
        }

        return $ids;
    }

    /**
     * Palette of the preset `$presetId` for `$mode` (light palette of a light pick, dark palette of a dark pick);
     * the configured default preset's palette for `custom`, null or an unknown id.
     */
    public function presetPalette(string $mode, ?string $presetId): AppearancePalette
    {
        $preset = is_string($presetId) ? $this->registry->get($presetId) : null;
        if ($preset instanceof AppearancePreset) {
            return $preset->palette($mode);
        }

        return $this->defaults()->palette($mode);
    }

    /**
     * Effective preset id of a mode: the stored one, else the configured default.
     *
     * @param array<string, mixed> $values appearance section values (field => value)
     */
    public function presetId(string $mode, array $values): string
    {
        $stored = $values[$mode === AppearancePreset::MODE_DARK ? 'preset_dark' : 'preset'] ?? null;
        if (is_string($stored) && trim($stored) !== '') {
            return $stored;
        }
        $defaults = $this->defaults();

        return $mode === AppearancePreset::MODE_DARK ? $defaults->themeIdDark : $defaults->themeId;
    }

    /**
     * The UiKit appearance of the stored section values (name, eyebrow and logos are the caller's): each colour is the
     * stored `#rrggbb` (lower-cased) or the selected preset's; the preset id is the selected one while the palette
     * equals it, else {@see Appearance::CUSTOM}. With no stored value at all this equals UiKit's
     * `ConfigAppearanceProvider` output (spec 220 §3.4.3).
     *
     * @param array<string, mixed> $values appearance section values (field => value; missing = not stored)
     */
    public function appearance(array $values, string $name, string $eyebrow): Appearance
    {
        $defaults  = $this->defaults();
        $lightId   = $this->presetId(AppearancePreset::MODE_LIGHT, $values);
        $darkId    = $this->presetId(AppearancePreset::MODE_DARK, $values);
        $lightBase = $this->presetPalette(AppearancePreset::MODE_LIGHT, $lightId);
        $darkBase  = $this->presetPalette(AppearancePreset::MODE_DARK, $darkId);
        $light     = $this->merge($lightBase, $values, 'light_');
        $dark      = $this->merge($darkBase, $values, 'dark_');

        $corner = $values['corner_style'] ?? null;
        $border = $values['border_strength'] ?? null;
        $footer = $values['footer_fixed'] ?? null;

        return new Appearance(
            $name,
            $eyebrow,
            $light,
            $dark,
            $this->registry->has($lightId) && $light->equals($lightBase) ? $lightId : Appearance::CUSTOM,
            $this->registry->has($darkId) && $dark->equals($darkBase) ? $darkId : Appearance::CUSTOM,
            is_string($corner) ? CornerStyle::fromLoose($corner)->value : $defaults->cornerStyle,
            is_string($border) ? BorderStrength::fromLoose($border)->value : $defaults->borderStrength,
            is_bool($footer) ? $footer : $defaults->footerFixed,
        );
    }

    /**
     * Brand contract colours of a UiKit palette. A preset value that is not `#rrggbb` (UiKit presets accept a wider
     * grammar) is replaced by the kit default preset's colour of that key (`slate` light, `midnight` dark), because the
     * contract, manifests, emails and PDFs need plain hex.
     */
    public static function colors(AppearancePalette $palette, string $mode): BrandColors
    {
        $fallback = $mode === AppearancePreset::MODE_DARK ? BuiltInAppearancePresets::get('midnight')->dark : BuiltInAppearancePresets::get('slate')->light;
        $source   = $palette->toArray();
        $kit      = $fallback->toArray();
        $colors   = [];
        foreach (self::CONTRACT_KEYS as $key => $contract) {
            $colors[$contract] = strtolower(ColorMath::isHex($source[$key]) ? $source[$key] : $kit[$key]);
        }

        return BrandColors::fromArray($colors);
    }

    /**
     * A stored colour value, lower-cased, or null when it is not `#rrggbb`.
     */
    public static function storedColor(mixed $value): ?string
    {
        return is_string($value) && ColorMath::isHex(trim($value)) ? strtolower(trim($value)) : null;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function merge(AppearancePalette $base, array $values, string $prefix): AppearancePalette
    {
        $colors = $base->toArray();
        foreach (self::KEYS as $key) {
            $stored = self::storedColor($values[$prefix . $key] ?? null);
            if ($stored !== null) {
                $colors[$key] = $stored;
            }
        }

        return AppearancePalette::fromArray($colors, $base);
    }
}
