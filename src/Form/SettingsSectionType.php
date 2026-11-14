<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Form;

use Closure;
use DateTimeImmutable;
use Nowo\DoctrineEncryptBundle\Form\SecretFieldApplier;
use Nowo\DoctrineEncryptBundle\Form\Type\SecretType;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\InstanceSettingsBundle\Expiry\ExpiringSecret;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Section\HiddenSecrets;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Nowo\UiKitBundle\Form\VisibleWhen;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\Util\FormUtil;
use Symfony\Component\Form\Util\StringUtil;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function abs;
use function array_replace_recursive;
use function array_reverse;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;
use function strtolower;

/**
 * Admin form of one settings section, built from its {@see SettingField}s (REQ-TWIG-005 rule 11: FormKit type).
 *
 * Plain fields are mapped on an array of the effective values. Secret fields use DoctrineEncrypt's
 * {@see SecretType} (unmapped, always rendered empty, "leave blank to keep" + a clear checkbox): the stored secret is
 * never handed to the form, only whether one is stored.
 *
 * Image fields (1.4) use {@see SettingImageType} (unmapped upload + "remove" when an image is stored).
 *
 * Colour fields (1.12) use {@see SettingColorType} (hex text input + native swatch + preset reset).
 *
 * Provider-dependent fields (1.3, {@see SettingField::withVisibleWhen()}) carry the UiKit `visible-when` row
 * attributes (`row_attr`), so the peer hides them without disabling or clearing them. On submit, a field hidden for the
 * submitted controlling value is reset to its current value before validation (no error on a row the user cannot
 * see; the controller does not save it); a hidden secret keeps only an explicit "clear" (deletes its stored row),
 * unless the `hidden_secrets` option is `keep_typed` (1.9): then a typed value is saved too.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig(NowoInstanceSettingsBundle::FORM_KIT_PROFILE)]
final class SettingsSectionType extends AbstractType
{
    use FormOptionsTrait;

    /** Placeholder handed to {@see SecretType} as `current_value` when a secret is stored (only its presence counts). */
    private const STORED_SECRET_MARKER = 'stored';

    /** Row class of an image field (1.12): one card per image field, laid out two per line by the bundle stylesheet. */
    public const IMAGE_CARD_CLASS = 'nowo-instance-settings-image-card';

    /** Row attribute carrying the form group of a field (1.5, {@see SettingField::withGroup()}). */
    public const GROUP_ATTRIBUTE = 'data-nowo-instance-settings-group';

    /**
     * Row attribute marking a revealable secret row for the reveal script (1.15), carrying the field name. It is set
     * only for the fields listed in the `revealable_secrets` option, which the page fills only for a user who may
     * reveal: the attribute is a marker for the script, never a value.
     */
    public const REVEAL_ATTRIBUTE = 'data-nowo-instance-settings-reveal';

    /**
     * Row attribute carrying the section name of a revealable secret row (1.15, audit F3): a page embedding two
     * sections binds every row to the Reveal root of its own section, so a field name declared in both sections never
     * reveals the other section's secret.
     */
    public const REVEAL_SECTION_ATTRIBUTE = 'data-nowo-instance-settings-reveal-section';

    /**
     * Row attribute of the expiry date row of an expiring secret (1.17), carrying the secret's field name. Never a value
     * of the secret.
     */
    public const EXPIRY_ATTRIBUTE = 'data-nowo-instance-settings-expiry-for';

    /**
     * Row attribute of a LOCKED secret row (1.17, `web_ui.reveal_in_input`): a stored revealable secret whose input the
     * reveal script makes read-only; a new value needs the row's Replace box ({@see self::replaceKey()}).
     */
    public const LOCKED_ATTRIBUTE = 'data-nowo-instance-settings-locked';

    /** Row attribute of the Replace box of a locked secret row (1.17), carrying the secret's field name. */
    public const REPLACE_ATTRIBUTE = 'data-nowo-instance-settings-replace-for';

    /** Suffix of the form child of a locked row's Replace box (1.17); a colon never occurs in a field name. */
    public const REPLACE_SUFFIX = ':replace';

    /** Row attribute of an expiry date row within the warning window (1.17): `expiring` or `expired`. */
    public const EXPIRY_STATE_ATTRIBUTE = 'data-nowo-instance-settings-expiry';

    /**
     * Adds one form field per setting field.
     *
     * @param array{section: SettingsSectionInterface, fields: array<string, SettingField>, stored_secrets: array<string, bool>, stored_images: array<string, bool>, stored_image_urls: array<string, string>, hidden_secrets: HiddenSecrets, revealable_secrets: list<string>, secret_expiry_dates: array<string, DateTimeImmutable>, secret_expiry_warnings: array<string, ExpiringSecret>, locked_secrets: list<string>} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $domain    = $options['section']->getTranslationDomain();
        $root      = $builder->getName();
        $dependent = [];
        foreach ($options['fields'] as $name => $field) {
            $rowAttr = [];
            if ($field->visibleWhen !== null) {
                $controller  = $field->visibleWhen['field'];
                $rowAttr     = VisibleWhen::attributes($root === '' ? $controller : $root . '[' . $controller . ']', $field->visibleWhen['in']);
                $dependent[] = $field;
            }
            if ($field->group !== null) {
                // 1.5 SettingField::withGroup(): a marker for host themes / stylesheets (no markup otherwise).
                $rowAttr[self::GROUP_ATTRIBUTE] = $field->group;
            }
            $base = [
                'label'              => $field->labelKey(),
                'help'               => $field->help,
                'translation_domain' => $domain,
                'required'           => $field->required && $field->type !== SettingType::Bool,
            ];
            if ($rowAttr !== []) {
                $base['row_attr'] = $rowAttr;
            }

            if ($field->secret) {
                $stored = $options['stored_secrets'][$name] ?? false;
                if (in_array($name, $options['revealable_secrets'], true)) {
                    // 1.15: the Reveal control of this row is cloned into it by the page script, from the root of this
                    // row's own section (audit F3).
                    $rowAttr[self::REVEAL_ATTRIBUTE]         = $name;
                    $rowAttr[self::REVEAL_SECTION_ATTRIBUTE] = $options['section']->getName();
                }
                $locked = in_array($name, $options['locked_secrets'], true);
                if ($locked) {
                    $rowAttr[self::LOCKED_ATTRIBUTE] = 'true';
                }
                $this->addWithDefaults($builder, $name, SecretType::class, array_replace_recursive([
                    'label'              => $field->labelKey(),
                    'help'               => $field->help,
                    'translation_domain' => $domain,
                    'row_attr'           => $rowAttr,
                    'current_value'      => $stored ? self::STORED_SECRET_MARKER : null,
                    'hint_visible_last'  => 0,
                    'required'           => false,
                    // SecretType is compound: by default its errors bubble to the root form, which the settings
                    // scaffold does not render. Keep them on the field so they show next to it.
                    'error_bubbling' => false,
                ], $field->formOptions));
                if ($locked) {
                    // 1.17: without it, a value submitted for the locked row is ignored (the stored secret is kept).
                    $this->addWithDefaults($builder, self::replaceKey($name), CheckboxType::class, [
                        'label'              => 'reveal.replace',
                        'translation_domain' => NowoInstanceSettingsBundle::TRANSLATION_DOMAIN,
                        'required'           => false,
                        'mapped'             => false,
                        'row_attr'           => ($base['row_attr'] ?? []) + [self::REPLACE_ATTRIBUTE => $name],
                    ]);
                }
                if ($field->expires) {
                    $this->addExpiryRow($builder, $name, $base['row_attr'] ?? [], $options['secret_expiry_dates'][$name] ?? null, $options['secret_expiry_warnings'][$name] ?? null);
                }

                continue;
            }

            if ($field->image !== null) {
                // 1.12: one card per image field; the label and help move to the file input (UiKit image input box), so
                // the field name is printed once.
                // 1.13 (audit LM29-I3): `image_input: false` keeps the pre-1.12 row (its label and help, no card).
                $card = $base;
                if (($field->formOptions['image_input'] ?? true) !== false) {
                    $card['label']    = false;
                    $card['help']     = null;
                    $card['row_attr'] = ($base['row_attr'] ?? []) + ['class' => self::IMAGE_CARD_CLASS];
                }
                $this->addWithDefaults($builder, $name, SettingImageType::class, array_replace_recursive($card, [
                    'required'    => false,
                    'stored'      => $options['stored_images'][$name] ?? false,
                    'current_url' => $options['stored_image_urls'][$name] ?? null,
                    'max_bytes'   => $field->image->maxBytes,
                    'types'       => $field->image->types,
                    'spec'        => $field->image,
                    'file_label'  => $field->labelKey(),
                    'file_help'   => $field->help,
                ], $field->formOptions));

                continue;
            }

            [$type, $extra] = self::widget($field);
            if ($field->hasThemeCards()) {
                // 1.6 SettingField::withThemeCards(): the same choice as radio cards with swatches.
                $type  = ThemeCardsType::class;
                $extra = ['choices' => $field->choices];
            }
            if ($field->type === SettingType::Choice) {
                $extra['choice_translation_domain'] = $domain;
            }
            $this->addWithDefaults($builder, $name, $type, array_replace_recursive($base, $extra, $field->formOptions));
        }

        if ($dependent !== []) {
            $keepTyped = $options['hidden_secrets'] === HiddenSecrets::KeepTyped;
            $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($dependent, $keepTyped): void {
                self::keepHiddenFields($event, $dependent, $keepTyped);
            });
        }
    }

    /**
     * Form child name of the Replace box of a locked secret row (1.17): `<field>:replace`.
     */
    public static function replaceKey(string $name): string
    {
        return $name . self::REPLACE_SUFFIX;
    }

    /**
     * The expiry date row of an expiring secret (1.17), right after the secret row: a date-only input (UTC on both
     * sides, so the browser's day is the stored day), unmapped, prefilled with the stored date. The help text is the
     * warning while the date is within `warning_days` or passed; every text goes through the translator and Twig's
     * escaping (no `help_html`).
     *
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, string> $rowAttr the secret's visible-when / group row attributes
     */
    private function addExpiryRow(FormBuilderInterface $builder, string $name, array $rowAttr, ?DateTimeImmutable $date, ?ExpiringSecret $warning): void
    {
        $rowAttr[self::EXPIRY_ATTRIBUTE] = $name;
        $help                            = 'secret_expiry.help';
        $parameters                      = [];
        if ($warning instanceof ExpiringSecret) {
            $rowAttr[self::EXPIRY_STATE_ATTRIBUTE] = $warning->isExpired() ? 'expired' : 'expiring';
            $help                                  = $warning->isExpired() ? 'secret_expiry.warning_expired' : 'secret_expiry.warning_expiring';
            $parameters                            = ['%date%' => $warning->expiresOnString(), '%days%' => (string) abs($warning->daysLeft)];
        }
        $this->addWithDefaults($builder, SettingField::expiryKey($name), DateType::class, [
            'label'                       => 'secret_expiry.label',
            'translation_domain'          => NowoInstanceSettingsBundle::TRANSLATION_DOMAIN,
            'help'                        => $help,
            'help_translation_parameters' => $parameters,
            'row_attr'                    => $rowAttr,
            'widget'                      => 'single_text',
            'input'                       => 'datetime_immutable',
            'model_timezone'              => 'UTC',
            'view_timezone'               => 'UTC',
            'required'                    => false,
            'mapped'                      => false,
            'data'                        => $date,
        ]);
    }

    /**
     * Replaces the submitted value of every field hidden for the submitted controlling value with its current view
     * value (a secret with "blank = keep"), so a hidden row never fails validation and re-renders with its stored
     * value. With `$keepTypedSecrets` (1.9, {@see HiddenSecrets::KeepTyped}) a hidden secret is left as submitted, so a
     * typed value is saved (its own constraints run; it is never `required`).
     *
     * @param list<SettingField> $dependent provider-dependent fields
     */
    public static function keepHiddenFields(FormEvent $event, array $dependent, bool $keepTypedSecrets = false): void
    {
        $data = $event->getData();
        if (!is_array($data)) {
            return;
        }
        $form = $event->getForm();

        // Controlling values normalized like the submitted form data the controller decides on.
        $controllers = [];
        foreach ($dependent as $field) {
            $controller = $field->visibleWhen['field'] ?? '';
            if ($form->has($controller)) {
                $controllers[$controller] = self::submittedValue($form->get($controller), $data[$controller] ?? null);
            }
        }

        foreach ($dependent as $field) {
            if ($field->isVisibleFor($controllers) || !$form->has($field->name)) {
                continue;
            }
            if ($field->secret && $keepTypedSecrets) {
                continue;
            }
            if ($field->secret) {
                // Only an explicit "clear" survives on a hidden secret (deletes the stored row, never writes a value).
                $submitted          = $data[$field->name] ?? null;
                $clear              = is_array($submitted) ? ($submitted[SecretFieldApplier::KEY_CLEAR] ?? null) : null;
                $data[$field->name] = is_string($clear) && $form->get($field->name)->has(SecretFieldApplier::KEY_CLEAR) ? [SecretFieldApplier::KEY_CLEAR => $clear] : [];
                // 1.17: its expiry date row is hidden too: keep the stored date.
                $expiry = SettingField::expiryKey($field->name);
                if ($form->has($expiry)) {
                    $data[$expiry] = $form->get($expiry)->getViewData();
                }

                continue;
            }
            $data[$field->name] = $form->get($field->name)->getViewData();
        }
        $event->setData($data);
    }

    /**
     * Raw submitted value of a controlling field normalized as its form type does (`trim`, then the view and model
     * transformers in reverse, as {@see Form::submit()}, with `empty_data` for an empty submission): a checkbox gives a
     * bool, an integer field an int, a choice its choice value. A value the type cannot transform is returned raw (the form
     * reports it as invalid anyway).
     *
     * @param FormInterface<mixed> $child
     */
    public static function submittedValue(FormInterface $child, mixed $raw): mixed
    {
        $config = $child->getConfig();
        if (is_string($raw) && $config->getOption('trim') === true) {
            $raw = StringUtil::trim($raw);
        }
        if (FormUtil::isEmpty($raw)) {
            // Like Form::submit(): an empty submission takes `empty_data` (a closure receives the form and the value).
            $emptyData = $config->getEmptyData();
            $raw       = $emptyData instanceof Closure ? $emptyData($child, $raw) : $emptyData;
        }

        $value = $raw;
        try {
            foreach (array_reverse($config->getViewTransformers()) as $transformer) {
                $value = $transformer->reverseTransform($value);
            }
            foreach (array_reverse($config->getModelTransformers()) as $transformer) {
                $value = $transformer->reverseTransform($value);
            }
        } catch (TransformationFailedException) {
            return $raw;
        }

        return $value;
    }

    /**
     * Options: `section` and `fields` (required), `stored_secrets` and `stored_images` (field => bool), `stored_image_urls`
     * (1.12: field => URL of the stored image, shown in the image input box),
     * `hidden_secrets` (1.9: {@see HiddenSecrets} or its value, default `drop`), `revealable_secrets` (1.15: the secret
     * field names whose row carries the Reveal marker; the page passes them only for a user who may reveal),
     * `secret_expiry_dates` (1.17: field => stored expiry date) and `secret_expiry_warnings` (1.17: field =>
     * {@see ExpiringSecret} within the warning window), `locked_secrets` (1.17: the secret rows locked by
     * `web_ui.reveal_in_input`; each gets a Replace box).
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['section', 'fields']);
        $resolver->setAllowedTypes('section', SettingsSectionInterface::class);
        $resolver->setAllowedTypes('fields', 'array');
        $resolver->setDefaults([
            'data_class'         => null,
            'stored_secrets'     => [],
            'stored_images'      => [],
            'stored_image_urls'  => [],
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'csrf_token_id'      => 'nowo_instance_settings_section',
            'translation_domain' => NowoInstanceSettingsBundle::TRANSLATION_DOMAIN,
        ]);
        $resolver->setAllowedTypes('stored_secrets', 'array');
        $resolver->setAllowedTypes('stored_images', 'array');
        $resolver->setAllowedTypes('stored_image_urls', 'array');
        $resolver->setDefault('revealable_secrets', []);
        $resolver->setDefault('locked_secrets', []);
        $resolver->setAllowedTypes('locked_secrets', 'string[]');
        $resolver->setDefault('secret_expiry_dates', []);
        $resolver->setAllowedTypes('secret_expiry_dates', 'array');
        $resolver->setDefault('secret_expiry_warnings', []);
        $resolver->setAllowedTypes('secret_expiry_warnings', 'array');
        $resolver->setAllowedTypes('revealable_secrets', 'string[]');
        $resolver->setAllowedTypes('csrf_token_id', 'string');
        $resolver->setDefault('hidden_secrets', HiddenSecrets::Drop);
        $resolver->setAllowedTypes('hidden_secrets', [HiddenSecrets::class, 'string']);
        $resolver->setNormalizer('hidden_secrets', static function (Options $options, HiddenSecrets|string $value): HiddenSecrets {
            if ($value instanceof HiddenSecrets) {
                return $value;
            }

            return HiddenSecrets::tryFrom($value) ?? throw new InvalidOptionsException(sprintf('The "hidden_secrets" option is "drop" or "keep_typed", got "%s".', $value));
        });
    }

    /**
     * Symfony form type and type-specific options of a plain field (image fields use {@see SettingImageType}).
     *
     * @return array{0: class-string<FormTypeInterface<mixed>>, 1: array<string, mixed>}
     */
    public static function widget(SettingField $field): array
    {
        return match ($field->type) {
            SettingType::String => [TextType::class, []],
            SettingType::Text   => [TextareaType::class, ['attr' => ['rows' => 4]]],
            SettingType::Email  => [EmailType::class, []],
            SettingType::Url    => [UrlType::class, ['default_protocol' => null]],
            SettingType::Bool   => [CheckboxType::class, []],
            SettingType::Int    => [IntegerType::class, []],
            SettingType::Float  => [NumberType::class, ['html5' => true, 'scale' => null]],
            SettingType::Choice => [ChoiceType::class, ['choices' => $field->choices, 'placeholder' => $field->required ? null : '']],
            SettingType::Image  => [SettingImageType::class, []],
            // 1.12: hex input (submitted) + native swatch + "Use the preset colour"; preset_from comes in formOptions.
            SettingType::Color => [SettingColorType::class, [
                'attr'                   => ['maxlength' => 7, 'autocomplete' => 'off', 'spellcheck' => 'false'],
                SettingColorType::PRESET => is_string($field->default) ? strtolower($field->default) : null,
            ]],
        };
    }
}
