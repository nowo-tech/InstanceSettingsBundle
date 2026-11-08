<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Form;

use Nowo\InstanceSettingsBundle\Form\PortabilityExportType;
use Nowo\InstanceSettingsBundle\Form\SettingImageType;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\FormFactorySupport;
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
        self::assertSame('image.replace', $logo->get(SettingImageType::FILE)->getConfig()->getOption('label'));
        self::assertSame('image/png,image/webp,image/jpeg', $logo->get(SettingImageType::FILE)->getConfig()->getOption('attr')['accept']);
        self::assertInstanceOf(CheckboxType::class, $logo->get(SettingImageType::REMOVE)->getConfig()->getType()->getInnerType());

        $scan = $form->get('scan');
        self::assertFalse($scan->has(SettingImageType::REMOVE), 'nothing stored, nothing to remove');
        self::assertSame('image.upload', $scan->get(SettingImageType::FILE)->getConfig()->getOption('label'));
        self::assertSame('image/jpeg', $scan->get(SettingImageType::FILE)->getConfig()->getOption('attr')['accept']);

        self::assertTrue($form->createView()->vars['multipart']);
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
