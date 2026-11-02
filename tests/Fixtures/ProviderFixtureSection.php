<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Provider-dependent fields (1.3): a provider choice, a required SMTP host, a constrained API region and a secret
 * key per provider, plus a bool-controlled field.
 */
final class ProviderFixtureSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'transport';
    }

    public function getFields(): iterable
    {
        yield SettingField::choice('provider', ['transport.smtp' => 'smtp', 'transport.api' => 'api', 'transport.none' => 'none'], 'none', 'transport.provider', required: true);
        yield SettingField::string('label', null, 'transport.label');
        yield SettingField::string('smtp_host', null, 'transport.smtp_host', required: true)->withVisibleWhen('provider', ['smtp']);
        yield SettingField::int('smtp_port', 25, 'transport.smtp_port')->withVisibleWhen('provider', ['smtp']);
        yield SettingField::string('api_region', null, 'transport.api_region', constraints: [new Regex('/^[a-z]{2}$/')])->withVisibleWhen('provider', ['api']);
        yield SettingField::secret('api_key', 'transport.api_key')->withVisibleWhen('provider', ['api']);
        yield SettingField::bool('tracking', false, 'transport.tracking');
        yield SettingField::string('tracking_domain', null, 'transport.tracking_domain')->withVisibleWhen('tracking', [true]);
    }
}
