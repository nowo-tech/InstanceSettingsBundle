<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\UiKitBundle\Form\ImageInputTypeExtension;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
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
 * 1.12: the file input is UiKit's image input (2.47, {@see ImageInputTypeExtension}): one box showing the stored image
 * (`current_url`) or the default (`default_url`, with a "Default" badge), a local preview of a picked or dropped file
 * before saving, and Replace / Remove / Undo in the box (the remove checkbox is rendered inside it). The file input
 * carries the field's label and help (`file_label`, `file_help`), so the name appears once. Without JavaScript: the
 * current image, the plain file input and the remove checkbox. `image_input: false` keeps the pre-1.12 markup.
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
     * @param array{stored: bool, max_bytes: int, types: list<string>, translation_domain: string, crop: array<mixed>|bool|null, spec: ImageSpec|null, image_input: bool, current_url: string|null, default_url: string|null, backdrop: string, file_label: string|null, file_help: string|null} $options
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
        $file = [
            'label'              => $options['stored'] ? 'image.replace' : 'image.upload',
            'translation_domain' => NowoInstanceSettingsBundle::TRANSLATION_DOMAIN,
            'required'           => false,
            'mapped'             => false,
            'attr'               => $attr,
            'constraints'        => [new File(maxSize: max(1, $options['max_bytes']))],
        ];
        if ($options['image_input']) {
            // 1.12: UiKit's image input box. The stored URL goes through a closure: a value UiKit refuses renders the
            // box without it (one warning logged) instead of failing the page.
            $current = $options['stored'] ? $options['current_url'] : null;
            $file    = [
                'label'                         => $options['file_label'] ?? $file['label'],
                'help'                          => $options['file_help'],
                'translation_domain'            => $options['file_label'] === null ? NowoInstanceSettingsBundle::TRANSLATION_DOMAIN : $options['translation_domain'],
                ImageInputTypeExtension::OPTION => [
                    'current_url'  => static fn (): ?string => $current,
                    'default_url'  => $options['default_url'],
                    'remove_field' => $options['stored'] ? self::REMOVE : null,
                    'backdrop'     => $options['backdrop'],
                ],
            ] + $file;
        }
        $this->addWithDefaults($builder, self::FILE, FileType::class, $file);
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
     * Whether a direct child of `$form` is an image field shown in UiKit's image input box (`image_input` true, 1.13,
     * audit LM29-I3): the page then loads UiKit's image-input script and the bundle stylesheet (cards).
     *
     * @param FormInterface<mixed> $form
     */
    public static function inputInForm(FormInterface $form): bool
    {
        foreach ($form as $child) {
            if ($child->getConfig()->getType()->getInnerType() instanceof self && $child->getConfig()->getOption('image_input') === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Options: `stored` (an image is stored), `max_bytes`, `types` (accepted types), `preview` (1.5: the section page shows
     * the stored image above the form, through its file route URL only; set it with
     * `SettingField::withFormOptions(['preview' => true])`), `crop` (1.8: null = the bundle default `files.crop`,
     * false = off, true or an array = on with these options, see {@see SettingImageCrop}) and `spec` (1.8: the field's
     * {@see ImageSpec}, the crop defaults are derived from it; without it, from `types` and `max_bytes`).
     * 1.12: `image_input` (default true: UiKit's image input box; false = the pre-1.12 file input), `current_url` (URL
     * of the stored image, shown while `stored`), `default_url` (image used when nothing is stored, e.g. a brand default
     * asset; set it with `SettingField::withFormOptions(['default_url' => …])`), `backdrop` (`checkerboard`, `light`,
     * `dark`), `file_label` / `file_help` (the field label and help, in `translation_domain`, carried by the file input).
     * `preview` is kept for BC; the box replaces the 1.5 preview list.
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
            'image_input'            => true,
            'current_url'            => null,
            'default_url'            => null,
            'backdrop'               => 'checkerboard',
            'file_label'             => null,
            'file_help'              => null,
        ]);
        $resolver->setAllowedTypes('image_input', 'bool');
        $resolver->setAllowedTypes('current_url', ['null', 'string']);
        $resolver->setAllowedTypes('default_url', ['null', 'string']);
        $resolver->setAllowedValues('backdrop', ['checkerboard', 'light', 'dark']);
        $resolver->setAllowedTypes('file_label', ['null', 'string']);
        $resolver->setAllowedTypes('file_help', ['null', 'string']);
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
