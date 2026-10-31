<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Form;

use Nowo\DoctrineEncryptBundle\Form\Type\SecretType;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Section\SettingType;
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
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_replace_recursive;

/**
 * Admin form of one settings section, built from its {@see SettingField}s (REQ-TWIG-005 rule 11: FormKit type).
 *
 * Plain fields are mapped on an array of the effective values. Secret fields use DoctrineEncrypt's
 * {@see SecretType} (unmapped, always rendered empty, "leave blank to keep" + a clear checkbox): the stored secret is
 * never handed to the form, only whether one is stored.
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
        $domain = $options['section']->getTranslationDomain();
        foreach ($options['fields'] as $name => $field) {
            $base = [
                'label'              => $field->labelKey(),
                'help'               => $field->help,
                'translation_domain' => $domain,
                'required'           => $field->required && $field->type !== SettingType::Bool,
            ];

            if ($field->secret) {
                $stored = $options['stored_secrets'][$name] ?? false;
                $this->addWithDefaults($builder, $name, SecretType::class, array_replace_recursive([
                    'label'              => $field->labelKey(),
                    'help'               => $field->help,
                    'translation_domain' => $domain,
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
