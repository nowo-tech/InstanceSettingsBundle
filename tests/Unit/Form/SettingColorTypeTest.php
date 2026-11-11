<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Form;

use Nowo\InstanceSettingsBundle\Form\SettingColorType;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Form\ThemeCardsType;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ColorFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\FormFactorySupport;
use Nowo\InstanceSettingsBundle\Ui\SettingsPageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Forms;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

/**
 * 1.12: the colour input type: a TextType (the hex input is the value) with the swatch / preset view variables.
 */
final class SettingColorTypeTest extends TestCase
{
    public function testAStandaloneInputShowsItsValueElseThePreset(): void
    {
        $factory = Forms::createFormFactory();
        $view    = $factory->createNamed('accent', SettingColorType::class, '#ABCDEF', [SettingColorType::PRESET => '#112233'])->createView();

        self::assertSame(TextType::class, (new SettingColorType())->getParent());
        self::assertContains(SettingColorType::BLOCK_PREFIX, $view->vars['block_prefixes']);
        self::assertSame('#abcdef', $view->vars['swatch']);
        self::assertSame('#112233', $view->vars['preset']);
        self::assertSame('#112233', $view->vars['static_preset']);
        self::assertNull($view->vars['preset_from']);
        self::assertNull($view->vars['preset_map']);
        self::assertSame('accent_label', $view->vars['label_attr']['id']);

        $empty = $factory->createNamed('accent', SettingColorType::class, null, [SettingColorType::PRESET => '#112233'])->createView();
        self::assertSame('#112233', $empty->vars['swatch']);
        $stored = $factory->createNamed('accent', SettingColorType::class, '#112233', [SettingColorType::PRESET => '#112233'])->createView();
        self::assertSame('#112233', $stored->vars['value'], 'the form data is shown as is (the section form passes null for an unstored colour)');
        $none = $factory->createNamed('accent', SettingColorType::class, 'red')->createView();
        self::assertSame(SettingColorType::FALLBACK_SWATCH, $none->vars['swatch'], 'no preset, invalid value: what a bare ColorType shows');

        $form = $factory->createNamed('accent', SettingColorType::class);
        $form->submit('#FFEEDD');
        self::assertSame('#FFEEDD', $form->getData(), 'the hex input is submitted as typed (the save normalises it)');
    }

    public function testPresetFromResolvesTheSourceInputName(): void
    {
        $factory = Forms::createFormFactory();
        $form    = $factory->createNamedBuilder('settings_section', FormType::class, ['scheme' => 'dark'])
            ->add('scheme', TextType::class)
            ->add('link', SettingColorType::class, [SettingColorType::PRESET => '#000000', SettingColorType::PRESET_FROM => ['field' => 'scheme', 'colors' => ['dark' => '#DDEEFF']]])
            ->add('other', SettingColorType::class, [SettingColorType::PRESET_FROM => ['field' => 'missing', 'colors' => ['dark' => '#DDEEFF']]])
            ->getForm();
        $view = $form->createView();

        self::assertSame('settings_section[scheme]', $view['link']->vars['preset_from']);
        self::assertSame('#ddeeff', $view['link']->vars['swatch']);
        self::assertSame('#ddeeff', $view['link']->vars['preset']);
        self::assertSame('#000000', $view['link']->vars['static_preset']);
        self::assertSame('{"dark":"#ddeeff"}', $view['link']->vars['preset_map']);
        self::assertNull($view['other']->vars['preset'], 'a missing source keeps the static preset');

        $root = $factory->createNamedBuilder('', FormType::class, ['scheme' => 'x'])
            ->add('scheme', TextType::class)
            ->add('link', SettingColorType::class, [SettingColorType::PRESET => '#000000', SettingColorType::PRESET_FROM => ['field' => 'scheme', 'colors' => ['dark' => '#DDEEFF']]])
            ->getForm()->createView();
        self::assertSame('scheme', $root['link']->vars['preset_from'], 'unnamed root form: the bare field name');
        self::assertSame('#000000', $root['link']->vars['swatch'], 'unmapped source value: the static preset');

        $alone = $factory->createNamed('link', SettingColorType::class, null, [SettingColorType::PRESET_FROM => ['field' => 'scheme', 'colors' => ['dark' => '#DDEEFF']]])->createView();
        self::assertSame('scheme', $alone->vars['preset_from']);
        self::assertSame(SettingColorType::FALLBACK_SWATCH, $alone->vars['swatch']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'preset not a colour' => [[SettingColorType::PRESET => 'red']];
        yield 'preset_from without field' => [[SettingColorType::PRESET_FROM => ['colors' => []]]];
        yield 'preset_from without colours' => [[SettingColorType::PRESET_FROM => ['field' => 'a']]];
        yield 'preset_from invalid colour' => [[SettingColorType::PRESET_FROM => ['field' => 'a', 'colors' => ['x' => 'javascript:1']]]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRefused(array $options): void
    {
        $this->expectException(InvalidOptionsException::class);
        Forms::createFormFactory()->create(SettingColorType::class, null, $options);
    }

    public function testTheSectionFormUsesTheColourInputAndThePageAddsItsTheme(): void
    {
        $section = new ColorFixtureSection();
        $fields  = [];
        foreach ($section->getFields() as $field) {
            $fields[$field->name] = $field;
        }
        $form = FormFactorySupport::factory()->createNamed('settings_section', SettingsSectionType::class, ['accent' => '#1d4ed8', 'scheme' => 'light', 'link' => null, 'legacy' => null], ['section' => $section, 'fields' => $fields]);
        $view = $form->createView();

        self::assertContains(SettingColorType::BLOCK_PREFIX, $view['accent']->vars['block_prefixes']);
        self::assertSame('#1d4ed8', $view['accent']->vars['preset']);
        self::assertSame('7', (string) $view['accent']->vars['attr']['maxlength']);
        self::assertSame('settings_section[scheme]', $view['link']->vars['preset_from']);
        self::assertSame('#112233', $view['link']->vars['swatch']);
        self::assertNotContains(SettingColorType::BLOCK_PREFIX, $view['legacy']->vars['block_prefixes'], 'BC: String colours stay text inputs');
        self::assertSame([SettingColorType::class, ['attr' => ['maxlength' => 7, 'autocomplete' => 'off', 'spellcheck' => 'false'], SettingColorType::PRESET => null]], SettingsSectionType::widget(SettingField::color('x')));

        self::assertSame([SettingColorType::FORM_THEME], SettingsPageFactory::formThemes($view));
        self::assertTrue(SettingsPageFactory::hasColorRows($view));

        $both = Forms::createFormFactory()->createBuilder(FormType::class)
            ->add('preset', ThemeCardsType::class, ['choices' => ['A' => 'a']])
            ->add('accent', SettingColorType::class)
            ->getForm()->createView();
        self::assertSame([ThemeCardsType::FORM_THEME, SettingColorType::FORM_THEME], SettingsPageFactory::formThemes($both));

        $plain = Forms::createFormFactory()->createBuilder(FormType::class)->add('name', TextType::class)->getForm()->createView();
        self::assertSame([], SettingsPageFactory::formThemes($plain));
        self::assertFalse(SettingsPageFactory::hasColorRows($plain));
    }
}
