<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * 1.18.1 (Beacon report): a secret row shown only while a switch is on, next to a plain field, for the lock of
 * `web_ui.reveal_in_input` on a row hidden by its `visible_when` switch.
 */
final class SwitchedSecretFixtureSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'webhooks';
    }

    public function getFields(): iterable
    {
        yield SettingField::bool('enabled', false, 'webhooks.enabled');
        yield SettingField::string('label', null, 'webhooks.label');
        yield SettingField::secret('signing_secret', 'webhooks.signing_secret')->withVisibleWhen('enabled', [true]);
    }
}
