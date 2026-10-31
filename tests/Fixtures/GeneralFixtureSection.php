<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Section with every plain type, a constrained int and a non-portable flag.
 */
final class GeneralFixtureSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'general';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getFields(): iterable
    {
        yield SettingField::string('site_name', 'Acme', 'general.site_name', required: true);
        yield SettingField::text('footer', null, 'general.footer');
        yield SettingField::url('homepage', null, 'general.homepage');
        yield SettingField::int('page_size', 20, 'general.page_size', constraints: [new Range(min: 1, max: 100)]);
        yield SettingField::float('ratio', 1.5, 'general.ratio');
        yield SettingField::choice('mode', ['general.mode_light' => 'light', 'general.mode_dark' => 'dark'], 'light', 'general.mode');
        yield SettingField::bool('maintenance_lock', false, 'general.maintenance_lock')->notPortable();
    }
}
