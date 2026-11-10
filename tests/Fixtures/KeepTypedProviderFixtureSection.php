<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\HiddenSecrets;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionHiddenSecretsInterface;

/**
 * 1.9: the provider fixture with a per-section override saving secrets typed into hidden rows.
 */
final class KeepTypedProviderFixtureSection extends AbstractSettingsSection implements SettingsSectionHiddenSecretsInterface
{
    public function getName(): string
    {
        return 'transport';
    }

    public function getFields(): iterable
    {
        return (new ProviderFixtureSection())->getFields();
    }

    public function getHiddenSecrets(): HiddenSecrets
    {
        return HiddenSecrets::KeepTyped;
    }
}
