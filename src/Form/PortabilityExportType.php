<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Export form (POST + CSRF): downloads the JSON envelope, optionally with the sealed secrets (REQ-TWIG-005 rule 11).
 *
 * @extends AbstractType<null>
 */
#[FormKitConfig(NowoInstanceSettingsBundle::FORM_KIT_PROFILE)]
final class PortabilityExportType extends AbstractType
{
    use FormOptionsTrait;

    /** CSRF token id of the export form. */
    public const CSRF_TOKEN_ID = 'nowo_instance_settings_export';

    /**
     * Field `include_secrets` when sealed secrets are allowed.
     *
     * @param array{allow_secrets: bool} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['allow_secrets']) {
            $this->addWithDefaults($builder, 'include_secrets', CheckboxType::class, [
                'label'    => 'portability.export.include_secrets',
                'help'     => 'portability.export.include_secrets_help',
                'mapped'   => false,
                'required' => false,
            ]);
        }
    }

    /**
     * Option `allow_secrets`.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'allow_secrets'      => false,
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
            'translation_domain' => NowoInstanceSettingsBundle::TRANSLATION_DOMAIN,
        ]);
        $resolver->setAllowedTypes('allow_secrets', 'bool');
    }
}
