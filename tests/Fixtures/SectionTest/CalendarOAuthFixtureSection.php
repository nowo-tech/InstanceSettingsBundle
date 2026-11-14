<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsGroup;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionGroupsInterface;

/**
 * 1.18: a calendar OAuth section with a Google and a Microsoft subtab (declared groups), a section tester, a Google
 * group tester and no Microsoft tester. `mode` scripts what the testers answer (stateless: read from the store).
 */
final class CalendarOAuthFixtureSection extends AbstractSettingsSection implements SettingsSectionGroupsInterface
{
    public function getName(): string
    {
        return 'calendar_oauth';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getFields(): iterable
    {
        yield SettingField::string('mode', null, 'calendar_oauth.mode');
        yield SettingField::string('google_client_id', null, 'calendar_oauth.google_client_id')->withGroup('google');
        yield SettingField::secret('google_client_secret', 'calendar_oauth.google_client_secret')->withGroup('google');
        yield SettingField::string('microsoft_tenant', null, 'calendar_oauth.microsoft_tenant')->withGroup('microsoft');
        yield SettingField::secret('microsoft_client_secret', 'calendar_oauth.microsoft_client_secret')->withGroup('microsoft');
    }

    public function getGroups(): iterable
    {
        yield new SettingsGroup('google', 'calendar_oauth.group.google', fieldGroups: ['google'], rest: true);
        yield new SettingsGroup('microsoft', 'calendar_oauth.group.microsoft', fieldGroups: ['microsoft']);
    }
}
