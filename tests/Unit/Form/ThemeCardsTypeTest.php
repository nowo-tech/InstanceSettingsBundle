<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Form;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Form\ThemeCardsType;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Ui\SettingsPageFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Forms;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

/**
 * IS-2 (1.6): the theme-card type is an expanded single ChoiceType whose swatches only keep `#rrggbb` colours.
 */
final class ThemeCardsTypeTest extends TestCase
{
    public function testSwatchesKeepValidColoursOnly(): void
    {
        self::assertSame(
            ['a' => ['#aabbcc', '#000000'], '7' => [], 'b' => ['#111111', '#111111', '#111111', '#111111', '#111111', '#111111', '#111111', '#111111']],
            ThemeCardsType::normalizeSwatches([
                'a'    => ['#AABBCC', 'red" onmouseover="x', 'url(javascript:1)', '#abc', 12, '#000000'],
                7      => [],
                'skip' => 'not a list',
                'b'    => ['#111111', '#111111', '#111111', '#111111', '#111111', '#111111', '#111111', '#111111', '#111111'],
            ]),
        );
    }

    public function testTheTypeIsAnExpandedSingleChoice(): void
    {
        $factory = Forms::createFormFactory();
        $form    = $factory->createNamed('theme', ThemeCardsType::class, 'b', ['choices' => ['A' => 'a', 'B' => 'b'], ThemeCardsType::SWATCHES => ['a' => ['#ABCDEF', 'x']]]);
        $view    = $form->createView();

        self::assertSame(ChoiceType::class, (new ThemeCardsType())->getParent());
        self::assertTrue($view->vars['expanded']);
        self::assertFalse($view->vars['multiple']);
        self::assertSame(['a' => ['#abcdef']], $view->vars[ThemeCardsType::SWATCHES]);
        self::assertSame('theme_label', $view->vars['label_attr']['id']);
        self::assertContains(ThemeCardsType::BLOCK_PREFIX, $view->vars['block_prefixes']);
        self::assertCount(2, $view->children);

        $form->submit('a');
        self::assertSame('a', $form->getData());

        $this->expectException(InvalidOptionsException::class);
        $factory->create(ThemeCardsType::class, null, ['choices' => ['A' => 'a'], 'multiple' => true]);
    }

    public function testThePageAddsTheFormThemeOnlyForCardRows(): void
    {
        $factory = Forms::createFormFactory();
        $plain   = $factory->createBuilder(FormType::class)->add('preset', ChoiceType::class, ['choices' => ['A' => 'a']])->getForm()->createView();
        $cards   = $factory->createBuilder(FormType::class)->add('preset', ThemeCardsType::class, ['choices' => ['A' => 'a']])->getForm()->createView();

        self::assertSame([], SettingsPageFactory::formThemes($plain));
        self::assertSame([ThemeCardsType::FORM_THEME], SettingsPageFactory::formThemes($cards));
    }

    public function testThemeCardsAreForChoiceFieldsOnly(): void
    {
        $field = SettingField::choice('theme', ['A' => 'a'])->withThemeCards(['a' => ['#ABCDEF']]);
        self::assertTrue($field->hasThemeCards());
        self::assertSame(['a' => ['#abcdef']], $field->formOptions[ThemeCardsType::SWATCHES]);
        self::assertFalse(SettingField::choice('theme', ['A' => 'a'])->hasThemeCards());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Setting field "name": theme cards are only for choice fields.');
        SettingField::string('name')->withThemeCards();
    }
}
