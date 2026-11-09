<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Contracts\Translation\TranslatorInterface;

use function implode;
use function max;
use function min;

/**
 * Admin widget of an image setting field (1.4): a file input and, when an image is stored, a "remove" checkbox.
 * Unmapped: the controller hands the upload (or the removal) to the save, which checks and re-encodes it; the `accept`
 * attribute and the `File` size constraint are conveniences, the server never trusts the client type or name.
 *
 * 1.8: an opt-in crop step ({@see SettingImageCrop}): the file input then carries UiKit's image crop attributes, from
 * the `crop` option (per field, {@see SettingField::withCrop()}) or the bundle default `files.crop`. Off by default:
 * the input is then exactly as before.
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
     * @param array<string, mixed>|false $cropDefault bundle default `files.crop` (1.8): false = off, else the options
     *                                                every image field without its own crop setting gets
     * @param TranslatorInterface|null $translator translates the crop dialog texts (UiKit `image_crop.*`)
     */
    public function __construct(
        private readonly array|false $cropDefault = false,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Adds the file input and, with a stored image, the remove checkbox.
     *
     * @param array{stored: bool, max_bytes: int, types: list<string>, translation_domain: string, crop: array<mixed>|bool|null, spec: ImageSpec|null} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $attr = ['accept' => implode(',', $options['types'])];
        if ($options['crop'] !== false && ($options['crop'] !== null || $this->cropDefault !== false)) {
            $spec = $options['spec'] ?? new ImageSpec($options['types'] === [] ? ImageSpec::TYPES : $options['types'], min(ImageSpec::MAX_BYTES_CEILING, max(1, $options['max_bytes'])));
            $crop = $this->crop($spec, $options['crop']);
            if ($crop !== null) {
                // 1.8: the field's own attributes (accept) win over the printed crop attributes.
                $attr += SettingImageCrop::attributes($spec, $crop, $this->translator);
            }
        }
        $this->addWithDefaults($builder, self::FILE, FileType::class, [
            'label'              => $options['stored'] ? 'image.replace' : 'image.upload',
            'translation_domain' => NowoInstanceSettingsBundle::TRANSLATION_DOMAIN,
            'required'           => false,
            'mapped'             => false,
            'attr'               => $attr,
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
     * Options: `stored` (an image is stored), `max_bytes`, `types` (accepted types), `preview` (1.5: the section page shows
     * the stored image above the form, through its file route URL only; set it with
     * `SettingField::withFormOptions(['preview' => true])`), `crop` (1.8: null = the bundle default `files.crop`,
     * false = off, true or an array = on with these options, see {@see SettingImageCrop}) and `spec` (1.8: the field's
     * {@see ImageSpec}, the crop defaults are derived from it; without it, from `types` and `max_bytes`).
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'             => null,
            'mapped'                 => false,
            'required'               => false,
            'stored'                 => false,
            'max_bytes'              => 1_048_576,
            'types'                  => [],
            'preview'                => false,
            'error_bubbling'         => false,
            SettingImageCrop::OPTION => null,
            'spec'                   => null,
        ]);
        $resolver->setAllowedTypes(SettingImageCrop::OPTION, ['null', 'bool', 'array']);
        $resolver->setAllowedTypes('spec', ['null', ImageSpec::class]);
        $resolver->setAllowedTypes('preview', 'bool');
        $resolver->setAllowedTypes('stored', 'bool');
        $resolver->setAllowedTypes('max_bytes', 'int');
        $resolver->setAllowedTypes('types', 'array');
    }

    /**
     * Crop options of the field (null = no crop step): its own setting, with the bundle default under it; a field the
     * bundle default cannot crop is shown without it (audit JP-M1).
     *
     * @param array<mixed>|true|null $crop
     *
     * @return array<mixed>|null
     */
    private function crop(ImageSpec $spec, array|true|null $crop): ?array
    {
        $own = $crop === null ? null : ($crop === true ? [] : $crop);
        if ($this->cropDefault === false) {
            return $own;
        }

        return SettingImageCrop::withBundleDefault($spec, $own, $this->cropDefault);
    }
}
