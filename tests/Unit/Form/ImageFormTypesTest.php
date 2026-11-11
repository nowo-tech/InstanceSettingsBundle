<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Form;

use Nowo\InstanceSettingsBundle\Form\PortabilityExportType;
use Nowo\InstanceSettingsBundle\Form\SettingImageType;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\FormFactorySupport;
use Nowo\UiKitBundle\Form\ImageInputTypeExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;

final class ImageFormTypesTest extends TestCase
{
    public function testImageFieldsGetAFileInputAndRemoveWhenStored(): void
    {
        $registry = new SettingsSectionRegistry([new ImageFixtureSection()]);
        $form     = FormFactorySupport::factory()->create(SettingsSectionType::class, [], [
            'section'       => $registry->get('brand'),
            'fields'        => $registry->fields('brand'),
            'stored_images' => ['logo' => true],
        ]);

        $logo = $form->get('logo');
        self::assertInstanceOf(SettingImageType::class, $logo->getConfig()->getType()->getInnerType());
        self::assertFalse($logo->getConfig()->getMapped());
        self::assertFalse($logo->getConfig()->getRequired());
        self::assertInstanceOf(FileType::class, $logo->get(SettingImageType::FILE)->getConfig()->getType()->getInnerType());
        self::assertSame('logo', $logo->get(SettingImageType::FILE)->getConfig()->getOption('label'), '1.12: the file input carries the field label');
        self::assertFalse($logo->getConfig()->getOption('label'), 'the card row has no second label');
        self::assertSame(SettingsSectionType::IMAGE_CARD_CLASS, $logo->getConfig()->getOption('row_attr')['class']);
        self::assertSame('image/png,image/webp,image/jpeg', $logo->get(SettingImageType::FILE)->getConfig()->getOption('attr')['accept']);
        self::assertInstanceOf(CheckboxType::class, $logo->get(SettingImageType::REMOVE)->getConfig()->getType()->getInnerType());

        $scan = $form->get('scan');
        self::assertFalse($scan->has(SettingImageType::REMOVE), 'nothing stored, nothing to remove');
        self::assertSame('scan', $scan->get(SettingImageType::FILE)->getConfig()->getOption('label'));
        self::assertSame('image/jpeg', $scan->get(SettingImageType::FILE)->getConfig()->getOption('attr')['accept']);

        self::assertTrue($form->createView()->vars['multipart']);

        // Without the image input (image_input: false): the pre-1.12 labels.
        $plain = FormFactorySupport::factory()->create(SettingImageType::class, null, ['stored' => true, 'image_input' => false]);
        self::assertSame('image.replace', $plain->get(SettingImageType::FILE)->getConfig()->getOption('label'));
        self::assertFalse($plain->get(SettingImageType::FILE)->getConfig()->getOption(ImageInputTypeExtension::OPTION));
        $bare = FormFactorySupport::factory()->create(SettingImageType::class, null, ['current_url' => '/x.png', 'default_url' => '/d.png']);
        self::assertSame('image.upload', $bare->get(SettingImageType::FILE)->getConfig()->getOption('label'), 'no field label: the bundle wording');
        $view = $bare->createView();
        self::assertNull($view[SettingImageType::FILE]->vars[ImageInputTypeExtension::OPTION]['current_url'], 'not stored: no current image');
        self::assertSame('/d.png', $view[SettingImageType::FILE]->vars[ImageInputTypeExtension::OPTION]['default_url']);
        $stored = FormFactorySupport::factory()->create(SettingImageType::class, null, ['stored' => true, 'current_url' => '/x.png', 'backdrop' => 'dark'])->createView();
        self::assertSame('/x.png', $stored[SettingImageType::FILE]->vars[ImageInputTypeExtension::OPTION]['current_url']);
        self::assertSame(SettingImageType::REMOVE, $stored[SettingImageType::FILE]->vars[ImageInputTypeExtension::OPTION]['remove_field']);
        self::assertSame('dark', $stored[SettingImageType::FILE]->vars[ImageInputTypeExtension::OPTION]['backdrop']);
        self::assertSame([SettingImageType::class, []], SettingsSectionType::widget($registry->field('brand', 'logo')));
    }

    public function testExportOffersFilesOnlyWithImageFields(): void
    {
        $factory = FormFactorySupport::factory();
        self::assertFalse($factory->create(PortabilityExportType::class)->has('include_files'));

        $form = $factory->create(PortabilityExportType::class, null, ['allow_files' => true]);
        self::assertTrue($form->has('include_files'));
        self::assertTrue($form->get('include_files')->getData(), 'checked by default in the UI');
    }
}
