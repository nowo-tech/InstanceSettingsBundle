<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * 1.17: the revealable section with field groups (subtabs layout).
 */
final class GroupedRevealFixtureSection extends RevealFixtureSection
{
    public function getFields(): iterable
    {
        yield SettingField::string('client_id', null, 'oauth.client_id')->withGroup('general');
        yield SettingField::secret('client_secret', 'oauth.client_secret', revealable: true)->withGroup('keys');
    }
}
