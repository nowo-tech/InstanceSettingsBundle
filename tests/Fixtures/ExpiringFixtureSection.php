<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Attribute\AsSettingsSection;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * 1.17: expiring secrets — an API key with an expiry date (its label carries markup, to prove escaping), a revealable
 * certificate password with a date, a provider-dependent backup key with a date, and a plain secret without one.
 */
#[AsSettingsSection(priority: 25)]
class ExpiringFixtureSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'providers';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getFields(): iterable
    {
        yield SettingField::choice('mode', ['providers.mode_main' => 'main', 'providers.mode_backup' => 'backup'], 'main', 'providers.mode');
        yield SettingField::secret('api_key', 'providers.api_key', expires: true);
        yield SettingField::secret('webhook_token', 'providers.webhook_token');
        yield SettingField::secret('backup_key', 'providers.backup_key', expires: true)->withVisibleWhen('mode', ['backup']);
    }
}
