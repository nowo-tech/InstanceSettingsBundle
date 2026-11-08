<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Section;

use InvalidArgumentException;
use LogicException;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class ImageFieldDefinitionTest extends TestCase
{
    public function testImageFactoryAndCopiesKeepTheRules(): void
    {
        $field = SettingField::image('logo', 'brand.logo', 'brand.logo_help', ['image/png'], 2000, 300, 200, 10, 20, true, [64], true, true);

        self::assertSame(SettingType::Image, $field->type);
        self::assertTrue($field->required);
        self::assertNull($field->default);
        self::assertFalse($field->secret);
        self::assertFalse($field->type->isString());
        self::assertNotNull($field->image);
        self::assertSame([['image/png'], 2000, 300, 200, 10, 20, true, [64], true], [
            $field->image->types, $field->image->maxBytes, $field->image->maxWidth, $field->image->maxHeight, $field->image->minWidth, $field->image->minHeight, $field->image->square, $field->image->variants, $field->image->public,
        ]);
        self::assertSame($field->image, $field->notPortable()->image);
        self::assertSame($field->image, $field->withFormOptions(['attr' => ['x' => 1]])->image);
        self::assertNull(SettingField::string('a')->image);
        self::assertEquals(new ImageSpec(), (new SettingField('img', SettingType::Image))->image);
    }

    public function testImageReferencesMatchTheType(): void
    {
        self::assertTrue(SettingField::matchesType(SettingType::Image, 'isf:' . str_repeat('a', 64)));
        self::assertFalse(SettingField::matchesType(SettingType::Image, 'isf:' . str_repeat('A', 64)));
        self::assertFalse(SettingField::matchesType(SettingType::Image, 'isf:../../etc/passwd'));
        self::assertFalse(SettingField::matchesType(SettingType::Image, '/uploads/logo.png'));
        self::assertFalse(SettingField::matchesType(SettingType::Image, 1));
    }

    public function testImpossibleImageFieldsAreRefused(): void
    {
        foreach ([
            static fn (): SettingField => new SettingField('a', SettingType::Image, 'isf:' . str_repeat('a', 64)),
            static fn (): SettingField => new SettingField('a', SettingType::Image, secret: true),
            static fn (): SettingField => new SettingField('a', SettingType::String, image: new ImageSpec()),
            static fn (): SettingField => SettingField::image('a')->withVisibleWhen('mode', ['x']),
        ] as $build) {
            try {
                $build();
                self::fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNormalizerNeverAcceptsABareImageValue(): void
    {
        $normalizer = new ValueNormalizer();

        self::assertSame([null, ['This value is not a valid image.']], $normalizer->normalize(SettingField::image('a'), 'isf:' . str_repeat('a', 64)));
        self::assertSame([null, []], $normalizer->normalize(SettingField::image('a'), null));
        self::assertSame([null, ['This value should not be blank.']], $normalizer->normalize(SettingField::image('a', required: true), ''));
    }

    public function testRegistryKnowsImageFieldsAndFailsClosedWithoutGd(): void
    {
        self::assertTrue((new SettingsSectionRegistry([new ImageFixtureSection()]))->hasImageFields());
        self::assertFalse((new SettingsSectionRegistry([new GeneralFixtureSection()]))->hasImageFields());
        self::assertFalse((new SettingsSectionRegistry([new GeneralFixtureSection()], []))->hasImageFields(), 'no GD needed without image fields');
        self::assertFalse((new SettingsSectionRegistry([new GeneralFixtureSection()], null, false))->hasImageFields(), 'no flag needed without image fields');

        try {
            (new SettingsSectionRegistry([new ImageFixtureSection()], null, false))->names();
            self::fail('An image field without files.enabled must fail closed.');
        } catch (LogicException $e) {
            self::assertStringContainsString('declares the image field "logo": set nowo_instance_settings.files.enabled: true', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('need ext-gd');
        (new SettingsSectionRegistry([new ImageFixtureSection()], ['PNG Support' => true]))->names();
    }

    public function testAnImageCannotControlVisibility(): void
    {
        $section = new class extends AbstractSettingsSection {
            public function getName(): string
            {
                return 'x';
            }

            public function getFields(): iterable
            {
                yield SettingField::image('logo');
                yield SettingField::string('alt')->withVisibleWhen('logo', ['a']);
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is an image field');
        (new SettingsSectionRegistry([$section]))->names();
    }
}
