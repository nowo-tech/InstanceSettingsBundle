<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Form;

use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function is_array;
use function is_string;
use function json_encode;
use function preg_match;
use function strtolower;

use const JSON_THROW_ON_ERROR;

/**
 * Colour input of a {@see SettingType::Color} field (1.12): the hex text input (`#rrggbb`) is the submitted value,
 * paired with a native swatch (`<input type="color">`, {@see ColorType} semantics) and a "Use the preset colour" reset.
 *
 * A bare ColorType cannot be empty (a browser always posts `#000000`), so the swatch has no `name`: it is never submitted
 * and only mirrors the hex input. Empty hex = null = follow the preset; the swatch then shows the preset colour (the
 * `preset` option, or the colour {@see SettingField::withPresetFrom()} maps to the current value of the source field;
 * the page script follows a change of that field). The server saves a colour equal to the preset as null. The section
 * form passes null for a colour that is not stored (not its default), so an unstored colour shows empty and a stored
 * one, whatever its value, is shown and re-submitted as stored (1.12, audit LM-M1).
 *
 * Markup (`Form/color.html.twig`, block `nowo_instance_settings_color_widget`): the swatch and the reset are `hidden`
 * until the page script (`bundles/nowoinstancesettings/js/nowo-instance-settings-color.js`, deferred, nonce from the
 * request `csp_nonce`) shows them and keeps both inputs in sync, so without JavaScript only the hex input is shown and
 * submitted. No inline handler, no style attribute (strict CSP safe); the swatch is labelled by the row label.
 *
 * @extends AbstractType<string|null>
 */
final class SettingColorType extends AbstractType
{
    /** Block prefix of the widget. */
    public const BLOCK_PREFIX = 'nowo_instance_settings_color';

    /** Form theme holding the widget block (added to the section form only when such a row exists). */
    public const FORM_THEME = '@NowoInstanceSettingsBundle/Form/color.html.twig';

    /** Option and {@see SettingField::$formOptions} key: preset colour source `{field, colors}`. */
    public const PRESET_FROM = 'preset_from';

    /** Option: static preset colour (the field default). */
    public const PRESET = 'preset';

    /** Swatch value while nothing (or an invalid colour) is entered and there is no preset (what ColorType would post). */
    public const FALLBACK_SWATCH = '#000000';

    /**
     * Swatch, effective preset (`preset`), the option preset (`static_preset`, what an unmapped source value falls back
     * to) and the preset source (`preset_from` input name, `preset_map` JSON) of the view; the row label gets an id the swatch is labelled by.
     *
     * @param FormInterface<mixed> $form
     * @param array{preset: string|null, preset_from: array{field: string, colors: array<string, string>}|null} $options
     */
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $preset = $options[self::PRESET];
        $from   = $options[self::PRESET_FROM];
        $source = null;
        if ($from !== null) {
            $parent = $form->getParent();
            if ($parent instanceof FormInterface && $parent->has($from['field'])) {
                $preset = $from['colors'][SettingField::visibilityValue($parent->get($from['field'])->getViewData())] ?? $preset;
            }
            $parentName = $view->parent instanceof FormView ? (string) ($view->parent->vars['full_name'] ?? '') : '';
            $source     = $parentName === '' ? $from['field'] : $parentName . '[' . $from['field'] . ']';
        }

        $value                       = is_string($view->vars['value'] ?? null) ? strtolower($view->vars['value']) : '';
        $view->vars['preset']        = $preset;
        $view->vars['static_preset'] = $options[self::PRESET];
        $view->vars['swatch']        = preg_match(SettingField::COLOR_PATTERN, $value) === 1 ? $value : ($preset ?? self::FALLBACK_SWATCH);
        $view->vars['preset_from']   = $source;
        $view->vars['preset_map']    = $from === null ? null : json_encode($from['colors'], JSON_THROW_ON_ERROR);
        $view->vars['label_attr'] += ['id' => $view->vars['id'] . '_label'];
    }

    /**
     * Text input options plus `preset` (`#rrggbb` or null) and `preset_from` (`{field, colors}` or null; field name of
     * the same parent form, colours `#rrggbb` keyed by its value). Colours are lower-cased.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            self::PRESET      => null,
            self::PRESET_FROM => null,
        ]);
        $resolver->setAllowedTypes(self::PRESET, ['null', 'string']);
        $resolver->setAllowedTypes(self::PRESET_FROM, ['null', 'array']);
        $resolver->setNormalizer(self::PRESET, static function (Options $options, ?string $preset): ?string {
            if ($preset !== null && preg_match(SettingField::COLOR_PATTERN, $preset) !== 1) {
                throw new InvalidOptionsException('The "preset" option is a #rrggbb colour or null.');
            }

            return $preset === null ? null : strtolower($preset);
        });
        $resolver->setNormalizer(self::PRESET_FROM, static function (Options $options, ?array $from): ?array {
            if ($from === null) {
                return null;
            }
            if (!is_string($from['field'] ?? null) || !is_array($from['colors'] ?? null)) {
                throw new InvalidOptionsException('The "preset_from" option is {field: string, colors: array<value, #rrggbb>}.');
            }
            $colors = [];
            foreach ($from['colors'] as $value => $color) {
                if (!is_string($color) || preg_match(SettingField::COLOR_PATTERN, $color) !== 1) {
                    throw new InvalidOptionsException('The "preset_from" colours are #rrggbb.');
                }
                $colors[(string) $value] = strtolower($color);
            }

            return ['field' => $from['field'], 'colors' => $colors];
        });
    }

    /**
     * {@see TextType}: the hex input is the submitted value.
     */
    public function getParent(): string
    {
        return TextType::class;
    }

    /**
     * `nowo_instance_settings_color`.
     */
    public function getBlockPrefix(): string
    {
        return self::BLOCK_PREFIX;
    }
}
