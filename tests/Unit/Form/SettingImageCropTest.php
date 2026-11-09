<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Form;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Brand\BrandDefaults;
use Nowo\InstanceSettingsBundle\Brand\BrandSection;
use Nowo\InstanceSettingsBundle\Form\SettingImageCrop;
use Nowo\InstanceSettingsBundle\Form\SettingImageType;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\FormFactorySupport;
use Nowo\UiKitBundle\Form\ImageCrop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_keys;
use function json_decode;

use const ARRAY_FILTER_USE_KEY;
use const JSON_THROW_ON_ERROR;

/**
 * 1.8 (owner decision D-20261109-09): the opt-in crop step of image fields, on UiKit 2.43's image crop: defaults
 * derived from the field's rules, options checked against UiKit and the field, attributes on the file input, the
 * bundle default and the per-field switches.
 */
final class SettingImageCropTest extends TestCase
{
    public function testDefaultsAreDerivedFromTheFieldRules(): void
    {
        // A logo (free proportions, a box of 2400 × 800): the crop fits the smaller side whatever its proportions.
        self::assertSame(
            ['aspect' => 'free', 'min' => 1, 'max' => 800, 'format' => 'auto', 'max_bytes' => 996_147],
            SettingImageCrop::derive(new ImageSpec(maxWidth: 2400, maxHeight: 800)),
        );
        // A square field crops square, at least its minimum size; max capped at 2048.
        self::assertSame(
            ['aspect' => 1, 'min' => 512, 'max' => 2048, 'format' => 'auto', 'max_bytes' => 996_147],
            SettingImageCrop::derive(new ImageSpec(minWidth: 512, minHeight: 512, square: true)),
        );
        self::assertSame(
            ['aspect' => 1, 'min' => 64, 'max' => 1024, 'format' => 'auto', 'max_bytes' => 996_147],
            SettingImageCrop::derive(new ImageSpec(maxWidth: 1024, maxHeight: 1024, minWidth: 64, minHeight: 64, square: true)),
        );
        // The largest minimum wins; a minimum over the cap raises max; tiny fields still get UiKit's smallest max.
        self::assertSame(3000, SettingImageCrop::derive(new ImageSpec(minWidth: 3000, minHeight: 10))['max']);
        self::assertSame(3000, SettingImageCrop::derive(new ImageSpec(minWidth: 3000, minHeight: 10))['min']);
        self::assertSame(16, SettingImageCrop::derive(new ImageSpec(maxWidth: 8, maxHeight: 8))['max']);
        // max_bytes: 95 % of maxBytes, at least 1.
        self::assertSame(190_000, SettingImageCrop::derive(new ImageSpec(maxBytes: 200_000))['max_bytes']);
        self::assertSame(1, SettingImageCrop::derive(new ImageSpec(maxBytes: 1))['max_bytes']);
    }

    /**
     * @param list<string> $types
     */
    #[DataProvider('formats')]
    public function testTheFormatIsOneTheFieldTakes(array $types, string $format): void
    {
        $spec = new ImageSpec($types);
        self::assertSame($format, SettingImageCrop::derive($spec)['format']);
        self::assertSame($format, SettingImageCrop::resolve($spec, [])['format']);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function formats(): iterable
    {
        yield 'all three' => [ImageSpec::TYPES, 'auto'];
        yield 'png and webp' => [['image/png', 'image/webp'], 'webp'];
        yield 'png and jpeg' => [['image/png', 'image/jpeg'], 'png'];
        yield 'png only' => [['image/png'], 'png'];
        yield 'jpeg and webp' => [['image/jpeg', 'image/webp'], 'jpeg'];
        yield 'jpeg only' => [['image/jpeg'], 'jpeg'];
        yield 'webp only' => [['image/webp'], 'webp'];
    }

    public function testOptionsOverTheDefaults(): void
    {
        $config = SettingImageCrop::resolve(new ImageSpec(maxWidth: 2400, maxHeight: 800), ['aspect' => '3/1', 'max' => 2400, 'shape' => 'square', 'quality' => 0.8]);
        self::assertSame(['3/1', 2400, 0.8, 996_147], [$config['aspect'], $config['max'], $config['quality'], $config['max_bytes']]);

        // A circle on a square field (a mask; stored square), and no browser byte limit.
        $circle = SettingImageCrop::resolve(new ImageSpec(square: true), ['shape' => 'circle', 'bake_circle' => true, 'format' => 'png', 'max_bytes' => null]);
        self::assertSame(['circle', '1', true, null], [$circle['shape'], $circle['aspect'], $circle['bake_circle'], $circle['max_bytes']]);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('refused')]
    public function testOptionsTheFieldWouldRefuseThrow(ImageSpec $spec, array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        SettingImageCrop::resolve($spec, $options);
    }

    /**
     * @return iterable<string, array{ImageSpec, array<string, mixed>, string}>
     */
    public static function refused(): iterable
    {
        yield 'unknown option' => [new ImageSpec(), ['ratio' => 1], 'Unknown image crop option'];
        yield 'bad UiKit value' => [new ImageSpec(), ['max' => 9000], 'max is an integer'];
        yield 'png on a JPEG field' => [new ImageSpec(['image/jpeg']), ['format' => 'png'], 'crop format "png"'];
        yield 'auto without WebP' => [new ImageSpec(['image/png', 'image/jpeg']), ['format' => 'auto'], 'crop format "auto"'];
        yield 'webp on a PNG field' => [new ImageSpec(['image/png']), ['format' => 'webp'], 'crop format "webp"'];
        yield 'max_bytes over maxBytes' => [new ImageSpec(maxBytes: 200_000), ['max_bytes' => 200_001], 'over the field maxBytes'];
        yield 'non-square aspect' => [new ImageSpec(square: true), ['aspect' => '16/9'], 'aspect 1'];
        yield 'free on a square field' => [new ImageSpec(square: true), ['aspect' => 'free'], 'aspect 1'];
        yield 'max over the dimensions' => [new ImageSpec(maxWidth: 2400, maxHeight: 800), ['max' => 2401], 'over the field maximum'];
        yield 'min over max' => [new ImageSpec(), ['min' => 600, 'max' => 500], 'crop min (600)'];
        yield 'min over the smaller side' => [new ImageSpec(maxWidth: 2400, maxHeight: 800), ['min' => 801, 'max' => 2400], 'crop min (801)'];
        yield 'too small to crop' => [new ImageSpec(maxWidth: 8, maxHeight: 8), [], 'over the field maximum'];
    }

    public function testAttributesFollowUiKitsContract(): void
    {
        $attributes = SettingImageCrop::attributes(new ImageSpec(minWidth: 512, minHeight: 512, square: true), []);
        self::assertSame([
            ImageCrop::ATTRIBUTE                => '',
            ImageCrop::ATTRIBUTE . '-shape'     => 'square',
            ImageCrop::ATTRIBUTE . '-aspect'    => '1',
            ImageCrop::ATTRIBUTE . '-max'       => '2048',
            ImageCrop::ATTRIBUTE . '-format'    => 'auto',
            ImageCrop::ATTRIBUTE . '-min'       => '512',
            ImageCrop::ATTRIBUTE . '-max-bytes' => '996147',
            ImageCrop::ATTRIBUTE . '-quality'   => '0.92',
        ], array_filter($attributes, static fn (string $key): bool => $key !== ImageCrop::ATTRIBUTE . '-labels', ARRAY_FILTER_USE_KEY));
        self::assertSame('Crop the image', json_decode($attributes[ImageCrop::ATTRIBUTE . '-labels'], true, 512, JSON_THROW_ON_ERROR)['title']);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters = [], ?string $domain = null): string => $domain . ':' . $id);
        $labels = json_decode(SettingImageCrop::attributes(new ImageSpec(), ['labels' => ['apply' => 'Use']], $translator)[ImageCrop::ATTRIBUTE . '-labels'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['NowoUiKitBundle:image_crop.title', 'Use'], [$labels['title'], $labels['apply']]);
    }

    public function testTheBundleDefaultSetsOnlyOptionsThatFitEveryField(): void
    {
        self::assertSame(['shape' => 'circle', 'quality' => 0.8], SettingImageCrop::bundleDefault(['shape' => 'circle', 'quality' => 0.8]));
        foreach ([['aspect' => 1], ['max_bytes' => 10], ['labels' => ['nope' => 'x']], ['shape' => 'oval']] as $invalid) {
            try {
                SettingImageCrop::bundleDefault($invalid);
                self::fail('Expected a refused bundle default.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSettingFieldSwitches(): void
    {
        $field = SettingField::image('logo', maxWidth: 2400, maxHeight: 800);
        self::assertNull($field->crop(), 'the bundle default applies');
        self::assertSame([], $field->withCrop()->crop());
        self::assertSame(['shape' => 'circle'], $field->withCrop(['shape' => 'circle'])->crop());
        self::assertFalse($field->withoutCrop()->crop());
        self::assertSame([], $field->withFormOptions([SettingImageCrop::OPTION => true])->crop());
        self::assertSame([SettingImageCrop::OPTION => [], 'preview' => true], $field->withFormOptions(['preview' => true])->withCrop()->formOptions);

        try {
            SettingField::image('icon', square: true)->withCrop(['aspect' => '16/9']);
            self::fail('Expected a refused crop.');
        } catch (InvalidArgumentException $e) {
            self::assertStringStartsWith('Setting field "icon": ', $e->getMessage());
        }
        foreach ([static fn (): SettingField => SettingField::string('name')->withCrop(), static fn (): SettingField => SettingField::string('name')->withoutCrop()] as $call) {
            try {
                $call();
                self::fail('Expected a refusal for a non-image field.');
            } catch (InvalidArgumentException $e) {
                self::assertSame('Setting field "name": crop is only for image fields.', $e->getMessage());
            }
        }
    }

    public function testTheFileInputIsUnchangedWithoutCrop(): void
    {
        $form = $this->sectionForm(FormFactorySupport::factory());
        foreach (['logo', 'icon', 'scan', 'local_only'] as $name) {
            self::assertSame(['accept'], array_keys($this->fileAttr($form, $name)), $name);
        }
        self::assertFalse(SettingImageCrop::inForm($form));
    }

    public function testTheBundleDefaultCropsEveryImageFieldWithItsOwnDefaults(): void
    {
        $form = $this->sectionForm(FormFactorySupport::factory(cropDefault: ['shape' => 'circle']));
        self::assertTrue(SettingImageCrop::inForm($form));

        $logo = $this->fileAttr($form, 'logo');
        self::assertSame('image/png,image/webp,image/jpeg', $logo['accept'], 'accept stays first and unchanged');
        self::assertSame(['circle', '1', '800'], [$logo[ImageCrop::ATTRIBUTE . '-shape'], $logo[ImageCrop::ATTRIBUTE . '-aspect'], $logo[ImageCrop::ATTRIBUTE . '-max']]);
        $icon = $this->fileAttr($form, 'icon');
        self::assertSame(['1', '32', '2048'], [$icon[ImageCrop::ATTRIBUTE . '-aspect'], $icon[ImageCrop::ATTRIBUTE . '-min'], $icon[ImageCrop::ATTRIBUTE . '-max']]);
        $scan = $this->fileAttr($form, 'scan');
        self::assertSame(['jpeg', '190000'], [$scan[ImageCrop::ATTRIBUTE . '-format'], $scan[ImageCrop::ATTRIBUTE . '-max-bytes']]);
    }

    public function testPerFieldSwitchesWinOverTheBundleDefault(): void
    {
        $fields = [
            'logo' => SettingField::image('logo', maxWidth: 2400, maxHeight: 800)->withCrop(['aspect' => '3/1', 'max' => 2400]),
            'icon' => SettingField::image('icon', square: true)->withoutCrop(),
        ];
        $off = $this->sectionForm(FormFactorySupport::factory(), $fields);
        self::assertSame(['3/1', '2400'], [$this->fileAttr($off, 'logo')[ImageCrop::ATTRIBUTE . '-aspect'], $this->fileAttr($off, 'logo')[ImageCrop::ATTRIBUTE . '-max']]);
        self::assertSame(['accept'], array_keys($this->fileAttr($off, 'icon')));

        $on   = $this->sectionForm(FormFactorySupport::factory(cropDefault: ['shape' => 'circle', 'quality' => 0.5]), $fields);
        $logo = $this->fileAttr($on, 'logo');
        self::assertSame(['circle', '0.5'], [$logo[ImageCrop::ATTRIBUTE . '-shape'], $logo[ImageCrop::ATTRIBUTE . '-quality']], 'the bundle default fills what the field leaves out');
        self::assertSame(['accept'], array_keys($this->fileAttr($on, 'icon')), 'withoutCrop() wins');
    }

    public function testTheBrandFieldsGetTheirOwnCrops(): void
    {
        $section = new BrandSection(new BrandDefaults('Acme'));
        $form    = FormFactorySupport::factory(cropDefault: [])->create(SettingsSectionType::class, [], ['section' => $section, 'fields' => (new SettingsSectionRegistry([$section]))->fields(BrandSection::NAME)]);

        $crops = [];
        foreach (['logo_light', 'logo_dark', 'mark', 'app_icon', 'favicon'] as $name) {
            $attr         = $this->fileAttr($form, $name);
            $crops[$name] = [$attr[ImageCrop::ATTRIBUTE . '-aspect'], $attr[ImageCrop::ATTRIBUTE . '-min'] ?? '1', $attr[ImageCrop::ATTRIBUTE . '-max'], $attr[ImageCrop::ATTRIBUTE . '-max-bytes']];
        }
        self::assertSame([
            'logo_light' => ['free', '1', '800', '996147'],
            'logo_dark'  => ['free', '1', '800', '996147'],
            'mark'       => ['1', '64', '1024', '996147'],
            'app_icon'   => ['1', '512', '2048', '996147'],
            'favicon'    => ['1', '32', '2048', '996147'],
        ], $crops);
    }

    public function testTheTypeAloneDerivesFromItsTypesAndSize(): void
    {
        $form = FormFactorySupport::factory()->createNamedBuilder('root', FormType::class)
            ->add('plain', SettingImageType::class, ['types' => ['image/jpeg'], 'max_bytes' => 100_000, SettingImageCrop::OPTION => true])
            ->add('any', SettingImageType::class, [SettingImageCrop::OPTION => ['shape' => 'circle'], 'max_bytes' => 0])
            ->getForm();
        $plain = (array) $form->get('plain')->get(SettingImageType::FILE)->getConfig()->getOption('attr');
        self::assertSame(['jpeg', '95000'], [$plain[ImageCrop::ATTRIBUTE . '-format'], $plain[ImageCrop::ATTRIBUTE . '-max-bytes']]);
        $any = (array) $form->get('any')->get(SettingImageType::FILE)->getConfig()->getOption('attr');
        self::assertSame(['auto', '1', 'circle'], [$any[ImageCrop::ATTRIBUTE . '-format'], $any[ImageCrop::ATTRIBUTE . '-max-bytes'], $any[ImageCrop::ATTRIBUTE . '-shape']]);
    }

    /**
     * @param array<string, SettingField>|null $fields
     *
     * @return FormInterface<mixed>
     */
    private function sectionForm(FormFactoryInterface $factory, ?array $fields = null): FormInterface
    {
        $registry = new SettingsSectionRegistry([new ImageFixtureSection()]);

        return $factory->create(SettingsSectionType::class, [], ['section' => $registry->get('brand'), 'fields' => $fields ?? $registry->fields('brand')]);
    }

    /**
     * @param FormInterface<mixed> $form
     *
     * @return array<string, string>
     */
    private function fileAttr(FormInterface $form, string $name): array
    {
        /** @var array<string, string> $attr */
        $attr = $form->get($name)->get(SettingImageType::FILE)->getConfig()->getOption('attr');

        return $attr;
    }
}
