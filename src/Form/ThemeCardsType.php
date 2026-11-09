<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_slice;
use function is_array;
use function is_string;
use function preg_match;
use function strtolower;

/**
 * Theme-card picker (1.6, IS-2): a single choice rendered as a radio group of cards, each with a colour swatch of the
 * option (e.g. a UiKit appearance preset) and its label. A {@see ChoiceType} underneath (`expanded`, not `multiple`):
 * the submitted value, validation and the stored value are the plain choice's, only the markup changes.
 *
 * Markup (`Form/theme_cards.html.twig`, block `nowo_instance_settings_theme_cards_widget`): native radio inputs inside
 * labels, so the arrow keys move between options and the checked one is announced; the group carries
 * `role="radiogroup"` named by the row label and described by the help. Swatches are inline SVG rectangles whose
 * `fill` is a `#rrggbb` colour validated here (anything else is dropped), so no style attribute, no CSS and no
 * script is needed (strict CSP safe). Classes are UiKit slots (`cards`, `card`, `check`, `check_input`).
 *
 * A candidate to move to UiKit later (a generic card-radio widget); kept here until UiKit has one.
 *
 * @extends AbstractType<mixed>
 */
final class ThemeCardsType extends AbstractType
{
    /** Block prefix of the widget. */
    public const BLOCK_PREFIX = 'nowo_instance_settings_theme_cards';

    /** Form theme holding the widget block (added to the section form only when such a row exists). */
    public const FORM_THEME = '@NowoInstanceSettingsBundle/Form/theme_cards.html.twig';

    /** Option: choice value => swatch colours (`#rrggbb`). */
    public const SWATCHES = 'swatches';

    /** Accepted swatch colour. */
    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /** Most colours shown per card. */
    public const MAX_COLORS = 8;

    /**
     * Swatches of the view (valid colours only) and the row label id the radio group is named by.
     *
     * @param FormInterface<mixed> $form
     * @param array{swatches: array<string, list<string>>} $options
     */
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars[self::SWATCHES] = $options[self::SWATCHES];
        $view->vars['label_attr'] += ['id' => $view->vars['id'] . '_label'];
    }

    /**
     * Expanded single choice; `swatches` (choice value => list of `#rrggbb`) is normalised: keys as strings, invalid
     * colours dropped, colours lower-cased, at most {@see self::MAX_COLORS} per option.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'expanded'     => true,
            'multiple'     => false,
            'placeholder'  => null,
            self::SWATCHES => [],
        ]);
        $resolver->setAllowedValues('expanded', true);
        $resolver->setAllowedValues('multiple', false);
        $resolver->setAllowedTypes(self::SWATCHES, 'array');
        $resolver->setNormalizer(self::SWATCHES, static fn (Options $options, array $swatches): array => self::normalizeSwatches($swatches));
    }

    /**
     * {@see ChoiceType}.
     */
    public function getParent(): string
    {
        return ChoiceType::class;
    }

    /**
     * `nowo_instance_settings_theme_cards`.
     */
    public function getBlockPrefix(): string
    {
        return self::BLOCK_PREFIX;
    }

    /**
     * Valid swatches only: keys as strings, `#rrggbb` colours (lower-cased), at most {@see self::MAX_COLORS} each.
     *
     * @param array<mixed> $swatches
     *
     * @return array<string, list<string>>
     */
    public static function normalizeSwatches(array $swatches): array
    {
        $normalized = [];
        foreach ($swatches as $value => $colors) {
            if (!is_array($colors)) {
                continue;
            }
            $list = [];
            foreach ($colors as $color) {
                if (is_string($color) && preg_match(self::COLOR_PATTERN, $color) === 1) {
                    $list[] = strtolower($color);
                }
            }
            $normalized[(string) $value] = array_slice($list, 0, self::MAX_COLORS);
        }

        return $normalized;
    }
}
