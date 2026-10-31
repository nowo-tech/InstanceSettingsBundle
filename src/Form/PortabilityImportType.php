<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

use function max;

/**
 * Upload form of an instance configuration file (multipart, CSRF, size-capped; REQ-TWIG-005 rules 7 and 11).
 *
 * @extends AbstractType<null>
 */
#[FormKitConfig(NowoInstanceSettingsBundle::FORM_KIT_PROFILE)]
final class PortabilityImportType extends AbstractType
{
    use FormOptionsTrait;

    /** CSRF token id of the import form. */
    public const CSRF_TOKEN_ID = 'nowo_instance_settings_import';

    /**
     * Fields `file` (JSON, at most `max_bytes`) and `dry_run`.
     *
     * @param array{max_bytes: int} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 'file', FileType::class, [
            'label'       => 'portability.import.file',
            'help'        => 'portability.import.file_help',
            'mapped'      => false,
            'required'    => true,
            'attr'        => ['accept' => 'application/json,.json', 'data-testid' => 'instance-settings-import-file'],
            'constraints' => [
                new NotNull(message: 'Choose a JSON file.'),
                new File(
                    maxSize: max(1, $options['max_bytes']),
                    mimeTypes: ['application/json', 'text/json', 'text/plain'],
                    mimeTypesMessage: 'Upload a JSON file.',
                ),
            ],
        ]);
        $this->addWithDefaults($builder, 'dry_run', CheckboxType::class, [
            'label'    => 'portability.import.dry_run',
            'mapped'   => false,
            'required' => false,
        ]);
    }

    /**
     * Option `max_bytes` (upload size cap).
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => null,
            'max_bytes'          => 1_048_576,
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
            'translation_domain' => NowoInstanceSettingsBundle::TRANSLATION_DOMAIN,
        ]);
        $resolver->setAllowedTypes('max_bytes', 'int');
    }
}
