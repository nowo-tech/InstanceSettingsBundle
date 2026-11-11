<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * Audit LM-M1 (1.12): a colour with a default AND a preset source whose map covers every source value (the demo
 * `accent_color` pattern): the default is never the preset, so a stored colour equal to it is a custom colour.
 */
final class ColorDefaultFixtureSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'tones';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getFields(): iterable
    {
        yield SettingField::choice('scheme', ['Light' => 'light', 'Dark' => 'dark'], 'light', required: true)
            ->withFormOptions(['choice_translation_domain' => false]);
        yield SettingField::color('tone', '#2563eb', 'tones.tone')->withPresetFrom('scheme', ['light' => '#1d4ed8', 'dark' => '#93c5fd']);
    }
}
