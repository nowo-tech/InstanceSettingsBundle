<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Symfony\Component\Validator\Constraints\Range;

/**
 * 1.20: the section behind the runtime-setting tests (`chat.retention_days`, `chat.webhook_secret`, `chat.welcome`).
 */
final class ChatFixtureSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'chat';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getFields(): iterable
    {
        yield SettingField::int('retention_days', 90, 'chat.retention_days', constraints: [new Range(min: 1, max: 36500)]);
        yield SettingField::secret('webhook_secret', 'chat.webhook_secret');
        yield SettingField::string('welcome', 'Hello', 'chat.welcome');
    }
}
