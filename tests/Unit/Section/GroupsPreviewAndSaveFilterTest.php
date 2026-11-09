<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Section;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\File\StoredSettingFile;
use Nowo\InstanceSettingsBundle\Form\SettingImageType;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionSaveFilterInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Support\FormFactorySupport;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Ui\ImagePreview;
use PHPUnit\Framework\TestCase;

use function is_string;

/**
 * 1.5 additions shared by every section: SettingField::withGroup(), the image `preview` form option and
 * SettingsSectionSaveFilterInterface.
 */
final class GroupsPreviewAndSaveFilterTest extends TestCase
{
    public function testGroupIsKeptByEveryCopy(): void
    {
        $field = SettingField::string('host')->withGroup('colors.light');
        self::assertSame('colors.light', $field->group);
        self::assertSame('colors.light', $field->notPortable()->group);
        self::assertSame('colors.light', $field->withFormOptions(['attr' => []])->group);
        self::assertSame('colors.light', $field->withVisibleWhen('mode', ['a'])->group);
        self::assertSame('colors.light', SettingField::image('logo')->withGroup('a.b')->withGroup('colors.light')->group);
        self::assertNull(SettingField::string('host')->group);
    }

    public function testInvalidGroupsAreRefused(): void
    {
        foreach (['Colors', 'a..b', '.a', 'a.b.c.d.e', 'a-b', ''] as $group) {
            try {
                SettingField::string('host')->withGroup($group);
                self::fail($group);
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('invalid group', $e->getMessage());
            }
        }
    }

    public function testFormRowsCarryTheGroupOnlyWhenSet(): void
    {
        $section = $this->section([
            SettingField::string('plain'),
            SettingField::string('grouped')->withGroup('identity'),
            SettingField::secret('token')->withGroup('keys'),
            SettingField::string('dependent')->withVisibleWhen('plain', ['x'])->withGroup('identity'),
        ]);
        $registry = new SettingsSectionRegistry([$section]);
        $form     = FormFactorySupport::factory()->create(SettingsSectionType::class, [], ['section' => $section, 'fields' => $registry->fields('test')]);

        self::assertArrayNotHasKey(SettingsSectionType::GROUP_ATTRIBUTE, $form->get('plain')->getConfig()->getOption('row_attr'));
        self::assertSame('identity', $form->get('grouped')->getConfig()->getOption('row_attr')[SettingsSectionType::GROUP_ATTRIBUTE]);
        self::assertSame('keys', $form->get('token')->getConfig()->getOption('row_attr')[SettingsSectionType::GROUP_ATTRIBUTE]);
        $dependent = $form->get('dependent')->getConfig()->getOption('row_attr');
        self::assertSame('identity', $dependent[SettingsSectionType::GROUP_ATTRIBUTE]);
        self::assertCount(3, $dependent, 'visible-when attributes kept');
    }

    public function testImagePreviewOption(): void
    {
        $section  = $this->section([SettingField::image('logo')->withFormOptions(['preview' => true]), SettingField::image('scan')]);
        $registry = new SettingsSectionRegistry([$section], ['PNG Support' => true, 'JPEG Support' => true, 'WebP Support' => true]);
        $form     = FormFactorySupport::factory()->create(SettingsSectionType::class, [], ['section' => $section, 'fields' => $registry->fields('test')]);

        self::assertTrue($form->get('logo')->getConfig()->getOption('preview'));
        self::assertFalse($form->get('scan')->getConfig()->getOption('preview'));
        self::assertInstanceOf(SettingImageType::class, $form->get('scan')->getConfig()->getType()->getInnerType());
    }

    public function testImagePreviewScalesAndNeedsAUrl(): void
    {
        $field = SettingField::image('logo', 'brand.logo');
        $wide  = ImagePreview::of(new StoredSettingFile('brand', 'logo', str_repeat('a', 64), 'image/png', 2400, 800, 10, true, [0 => '/f/logo.png']), $field, 'app');
        self::assertNotNull($wide);
        self::assertSame(['logo', '/f/logo.png', 160, 53, 'brand.logo', 'app'], [$wide->field, $wide->url, $wide->width, $wide->height, $wide->label->key, $wide->label->domain]);

        $small = ImagePreview::of(new StoredSettingFile('brand', 'logo', str_repeat('a', 64), 'image/png', 16, 16, 10, true, [0 => '/f/x.png']), $field, 'app');
        self::assertSame([16, 16], [$small?->width, $small?->height], 'never scaled up');
        $tall = ImagePreview::of(new StoredSettingFile('brand', 'logo', str_repeat('a', 64), 'image/png', 1, 4000, 10, true, [0 => '/f/x.png']), $field, 'app');
        self::assertSame([1, 64], [$tall?->width, $tall?->height]);

        self::assertNull(ImagePreview::of(new StoredSettingFile('brand', 'logo', str_repeat('a', 64), 'image/png', 1, 1, 1, true, [0 => null]), $field, 'app'));
    }

    public function testSaveFilterRewritesValuesBeforeValidation(): void
    {
        $section = new class extends AbstractSettingsSection implements SettingsSectionSaveFilterInterface {
            /** @var array<string, mixed> */
            public array $seenCurrent = [];

            public function getName(): string
            {
                return 'test';
            }

            public function getFields(): iterable
            {
                return [SettingField::string('color', '#ffffff'), SettingField::string('other')];
            }

            public function filterValues(array $values, array $current): array
            {
                $this->seenCurrent = $current;
                if (isset($values['color']) && is_string($values['color'])) {
                    $values['color'] = strtolower($values['color']) === $current['color'] ? null : strtolower($values['color']);
                }

                return $values;
            }
        };
        $settings = new InstanceSettings(new InMemorySettingValueRepository(), new SettingsSectionRegistry([$section]));

        self::assertSame(['color'], $settings->save('test', ['color' => '#ABCDEF']));
        self::assertSame('#abcdef', $settings->get('test.color'));
        self::assertSame(['color' => '#ffffff', 'other' => null], $section->seenCurrent);
        // Equal to the current value: dropped (the row is removed, the default applies again).
        self::assertSame(['color'], $settings->save('test', ['color' => '#ABCDEF']));
        self::assertFalse($settings->isOverridden('test.color'));
    }

    /**
     * @param list<SettingField> $fields
     */
    private function section(array $fields): AbstractSettingsSection
    {
        return new class($fields) extends AbstractSettingsSection {
            /**
             * @param list<SettingField> $fields
             */
            public function __construct(private readonly array $fields)
            {
            }

            public function getName(): string
            {
                return 'test';
            }

            public function getFields(): iterable
            {
                return $this->fields;
            }
        };
    }
}
