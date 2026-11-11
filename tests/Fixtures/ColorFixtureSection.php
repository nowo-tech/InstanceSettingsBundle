<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SectionFallbackProviderInterface;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Colour fields (1.12): a static preset (the default), a preset following a choice, and a pre-1.12 String + Regex colour.
 * Fallbacks: the default accent (not seeded), the dark scheme, its link preset (not seeded) and a custom legacy colour.
 */
final class ColorFixtureSection extends AbstractSettingsSection implements SectionFallbackProviderInterface
{
    public function getName(): string
    {
        return 'palette';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getFields(): iterable
    {
        yield SettingField::color('accent', '#1D4ED8', 'palette.accent', 'palette.accent_help');
        yield SettingField::choice('scheme', ['Light' => 'light', 'Dark' => 'dark'], 'light', required: true)
            ->withFormOptions(['choice_translation_domain' => false]);
        yield SettingField::color('link', label: 'palette.link')->withPresetFrom('scheme', ['light' => '#112233', 'dark' => '#DDEEFF']);
        yield SettingField::string('legacy', label: 'palette.legacy', constraints: [new Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'This value is not a #rrggbb colour.')]);
    }

    public function getFallbacks(): iterable
    {
        yield SettingFallback::plain('accent', '#1D4ED8');
        yield SettingFallback::plain('scheme', 'dark');
        yield SettingFallback::plain('link', '#DDEEFF');
        yield SettingFallback::plain('legacy', '#ABCDEF');
    }
}
