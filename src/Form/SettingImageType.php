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

use function implode;
use function max;

/**
 * Admin widget of an image setting field (1.4): a file input and, when an image is stored, a "remove" checkbox.
 * Unmapped: the controller hands the upload (or the removal) to the save, which checks and re-encodes it; the `accept`
 * attribute and the `File` size constraint are conveniences, the server never trusts the client type or name.
 *
 * @extends AbstractType<null>
 */
#[FormKitConfig(NowoInstanceSettingsBundle::FORM_KIT_PROFILE)]
final class SettingImageType extends AbstractType
{
    use FormOptionsTrait;

    /** Child holding the upload. */
    public const FILE = 'file';

    /** Child asking to remove the stored image. */
    public const REMOVE = 'remove';

    /**
     * Adds the file input and, with a stored image, the remove checkbox.
     *
     * @param array{stored: bool, max_bytes: int, types: list<string>, translation_domain: string} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, self::FILE, FileType::class, [
            'label'              => $options['stored'] ? 'image.replace' : 'image.upload',
            'translation_domain' => NowoInstanceSettingsBundle::TRANSLATION_DOMAIN,
            'required'           => false,
            'mapped'             => false,
            'attr'               => ['accept' => implode(',', $options['types'])],
            'constraints'        => [new File(maxSize: max(1, $options['max_bytes']))],
        ]);
        if ($options['stored']) {
            $this->addWithDefaults($builder, self::REMOVE, CheckboxType::class, [
                'label'              => 'image.remove',
                'translation_domain' => NowoInstanceSettingsBundle::TRANSLATION_DOMAIN,
                'required'           => false,
                'mapped'             => false,
            ]);
        }
    }

    /**
     * Options: `stored` (an image is stored), `max_bytes`, `types` (accepted types).
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'     => null,
            'mapped'         => false,
            'required'       => false,
            'stored'         => false,
            'max_bytes'      => 1_048_576,
            'types'          => [],
            'error_bubbling' => false,
        ]);
        $resolver->setAllowedTypes('stored', 'bool');
        $resolver->setAllowedTypes('max_bytes', 'int');
        $resolver->setAllowedTypes('types', 'array');
    }
}
