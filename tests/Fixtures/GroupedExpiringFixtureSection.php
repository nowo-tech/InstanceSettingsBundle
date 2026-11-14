<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * 1.17: the expiring section with field groups, for the subtabs layout.
 */
final class GroupedExpiringFixtureSection extends ExpiringFixtureSection
{
    public function getFields(): iterable
    {
        yield SettingField::string('label', null, 'providers.mode')->withGroup('general');
        yield SettingField::secret('api_key', 'providers.api_key', expires: true)->withGroup('keys');
    }
}
