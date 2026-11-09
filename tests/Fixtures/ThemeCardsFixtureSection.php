<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * A host choice field rendered as theme cards (1.6), with hostile labels and swatch values.
 */
final class ThemeCardsFixtureSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'look';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getFields(): iterable
    {
        yield SettingField::choice('theme', ['<b>Bold</b>' => 'bold', 'Plain' => 'plain'], 'plain', 'look.theme', 'look.theme_help', required: true)
            ->withFormOptions(['choice_translation_domain' => false])
            ->withThemeCards(['bold' => ['#AABBCC', '#000"/><script>alert(1)</script>', 'red" onmouseover="alert(1)'], 'plain' => []]);
    }
}
