<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Attribute\AsSettingsSection;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * Mailer-like section (what MailKit contributes): sender, encrypted DSN, test recipient, a switch.
 */
#[AsSettingsSection(priority: 20)]
class MailerFixtureSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'mailer';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getIntro(): ?string
    {
        return 'mailer.intro';
    }

    public function getIcon(): ?string
    {
        return 'config';
    }

    public function getFields(): iterable
    {
        yield SettingField::email('from', 'noreply@example.test', 'mailer.from', 'mailer.from_help', required: true);
        yield SettingField::string('from_name', 'Example', 'mailer.from_name');
        yield SettingField::secret('dsn', 'mailer.dsn', 'mailer.dsn_help');
        yield SettingField::email('test_recipient', null, 'mailer.test_recipient');
        yield SettingField::bool('enabled', false, 'mailer.enabled');
    }
}
