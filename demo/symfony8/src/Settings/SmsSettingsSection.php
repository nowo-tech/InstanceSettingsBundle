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
        yield SettingField::string('account_id', null, 'sms.account_id');
        yield SettingField::secret('auth_token', 'sms.auth_token');
    }
}
