<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * 1.3.1 (audit N4): an optional provider choice whose default (`smtp`) shows a required secret, and a bool switch on
 * by default that shows a required field.
 */
final class DefaultProviderFixtureSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'relay';
    }

    public function getFields(): iterable
    {
        yield SettingField::choice('provider', ['relay.smtp' => 'smtp', 'relay.api' => 'api'], 'smtp', 'relay.provider');
        yield SettingField::secret('key', 'relay.key', required: true)->withVisibleWhen('provider', ['smtp']);
        yield SettingField::string('host', null, 'relay.host', required: true)->withVisibleWhen('provider', ['smtp']);
        yield SettingField::bool('tracking', true, 'relay.tracking');
        yield SettingField::string('tracking_domain', null, 'relay.tracking_domain', required: true)->withVisibleWhen('tracking', [true]);
    }
}
