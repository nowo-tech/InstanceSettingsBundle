<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsGroup;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionGroupsInterface;

/**
 * A host section with declared field groups (1.14): by form group, by field name plus a parent form group, and a rest
 * group taking the field no group claims (an image).
 */
final class GroupedFixtureSection extends AbstractSettingsSection implements SettingsSectionGroupsInterface
{
    public function getName(): string
    {
        return 'grouped';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getFields(): iterable
    {
        yield SettingField::string('title', 'Grouped', 'grouped.title', required: true)->withGroup('basics');
        yield SettingField::secret('api_key', 'grouped.api_key');
        yield SettingField::text('notes', null, 'grouped.notes')->withGroup('basics');
        yield SettingField::url('endpoint', null, 'grouped.endpoint')->withGroup('credentials.remote');
        yield SettingField::image('banner', 'grouped.banner', maxWidth: 2000, maxHeight: 2000);
    }

    public function getGroups(): iterable
    {
        yield new SettingsGroup('basics', fieldGroups: ['basics']);
        yield new SettingsGroup('credentials', 'grouped.credentials', fields: ['api_key'], fieldGroups: ['credentials']);
        yield new SettingsGroup('misc', rest: true);
    }
}
