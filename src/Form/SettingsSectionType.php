<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Form;

use Nowo\DoctrineEncryptBundle\Form\SecretFieldApplier;
use Nowo\DoctrineEncryptBundle\Form\Type\SecretType;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Nowo\UiKitBundle\Form\VisibleWhen;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\Util\StringUtil;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_replace_recursive;
use function in_array;
use function is_array;
use function is_string;

/**
 * Admin form of one settings section, built from its {@see SettingField}s (REQ-TWIG-005 rule 11: FormKit type).
 *
 * Plain fields are mapped on an array of the effective values. Secret fields use DoctrineEncrypt's
 * {@see SecretType} (unmapped, always rendered empty, "leave blank to keep" + a clear checkbox): the stored secret is
 * never handed to the form, only whether one is stored.
 *
 * Provider-dependent fields (1.3, {@see SettingField::withVisibleWhen()}) carry the UiKit `visible-when` row
 * attributes (`row_attr`), so the peer hides them without disabling or clearing them. On submit, a field hidden for the
 * submitted controlling value is reset to its current value before validation (no error on a row the user cannot
 * see; the controller does not save it); a hidden secret keeps only an explicit "clear" (deletes its stored row).
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig(NowoInstanceSettingsBundle::FORM_KIT_PROFILE)]
final class SettingsSectionType extends AbstractType
{
    use FormOptionsTrait;

    /** Placeholder handed to {@see SecretType} as `current_value` when a secret is stored (only its presence counts). */
    private const STORED_SECRET_MARKER = 'stored';

    /**
     * Adds one form field per setting field.
     *
     * @param array{section: SettingsSectionInterface, fields: array<string, SettingField>, stored_secrets: array<string, bool>} $options
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

                continue;
            }

            [$type, $extra] = self::widget($field);
            if ($field->type === SettingType::Choice) {
                $extra['choice_translation_domain'] = $domain;
            }
            $this->addWithDefaults($builder, $name, $type, array_replace_recursive($base, $extra, $field->formOptions));
        }

        if ($dependent !== []) {
            $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($dependent): void {
                self::keepHiddenFields($event, $dependent);
            });
        }
    }

    /**
     * Replaces the submitted value of every field hidden for the submitted controlling value with its current view
     * value (a secret with "blank = keep"), so a hidden row never fails validation and re-renders with its stored
     * value.
     *
     * @param list<SettingField> $dependent provider-dependent fields
     */
    public static function keepHiddenFields(FormEvent $event, array $dependent): void
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
            if ($field->secret) {
                // Only an explicit "clear" survives on a hidden secret (deletes the stored row, never writes a value).
                $submitted          = $data[$field->name] ?? null;
                $clear              = is_array($submitted) ? ($submitted[SecretFieldApplier::KEY_CLEAR] ?? null) : null;
                $data[$field->name] = is_string($clear) && $form->get($field->name)->has(SecretFieldApplier::KEY_CLEAR) ? [SecretFieldApplier::KEY_CLEAR => $clear] : [];

                continue;
            }
            $data[$field->name] = $form->get($field->name)->getViewData();
        }
        $event->setData($data);
    }

    /**
     * Raw submitted value of a controlling field normalized as its form type does: a checkbox gives a bool (any
     * submitted string but its `false_values` is checked), a trimmed text field a trimmed string, others the raw value.
     *
     * @param FormInterface<mixed> $child
     */
    public static function submittedValue(FormInterface $child, mixed $raw): mixed
    {
        $config = $child->getConfig();
        if ($config->hasOption('false_values')) {
            /** @var array<mixed> $false */
            $false = $config->getOption('false_values');

            return !in_array($raw, $false, true);
        }
        if (is_string($raw) && $config->getOption('trim') === true) {
            return StringUtil::trim($raw);
        }

        return $raw;
    }

    /**
     * Options: `section` and `fields` (required), `stored_secrets` (field => bool).
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['section', 'fields']);
        $resolver->setAllowedTypes('section', SettingsSectionInterface::class);
        $resolver->setAllowedTypes('fields', 'array');
        $resolver->setDefaults([
            'data_class'         => null,
            'stored_secrets'     => [],
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'csrf_token_id'      => 'nowo_instance_settings_section',
            'translation_domain' => NowoInstanceSettingsBundle::TRANSLATION_DOMAIN,
        ]);
        $resolver->setAllowedTypes('stored_secrets', 'array');
        $resolver->setAllowedTypes('csrf_token_id', 'string');
    }

    /**
     * Symfony form type and type-specific options of a plain field.
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
        };
    }
}
