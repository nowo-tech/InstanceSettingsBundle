<?php

declare(strict_types=1);

namespace App\Settings;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * What an SMS bundle contributes: provider, sender and encrypted credentials.
 */
final class SmsSettingsSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'sms';
    }

    public function getIntro(): ?string
    {
        return 'sms.intro';
    }

    public function getFields(): iterable
    {
        yield SettingField::choice('provider', ['sms.provider_null' => 'null', 'sms.provider_twilio' => 'twilio', 'sms.provider_bridge' => 'sms_bridge'], 'null', 'sms.provider', required: true);
        yield SettingField::string('sender', null, 'sms.sender');
        // Provider-dependent fields (1.3): shown only for Twilio / SMS Bridge; their values survive a provider switch.
        yield SettingField::string('account_id', null, 'sms.account_id')->withVisibleWhen('provider', ['twilio', 'sms_bridge']);
        yield SettingField::secret('auth_token', 'sms.auth_token')->withVisibleWhen('provider', ['twilio', 'sms_bridge']);
        yield SettingField::url('bridge_url', null, 'sms.bridge_url')->withVisibleWhen('provider', ['sms_bridge']);
    }
}
