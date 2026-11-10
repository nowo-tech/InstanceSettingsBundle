<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\HiddenSecrets;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionHiddenSecretsInterface;
use Symfony\Component\Validator\Constraints\Length;

/**
 * 1.9 (audit KB-I-L1): keep_typed with a constrained provider-dependent secret.
 */
final class KeepTypedStrictSecretFixtureSection extends AbstractSettingsSection implements SettingsSectionHiddenSecretsInterface
{
    public function getName(): string
    {
        return 'gateway';
    }

    public function getFields(): iterable
    {
        yield SettingField::choice('provider', ['gateway.a' => 'a', 'gateway.b' => 'b'], 'a', 'gateway.provider', required: true);
        yield SettingField::string('label', null, 'gateway.label');
        yield SettingField::secret('b_token', 'gateway.b_token', constraints: [new Length(min: 8)])->withVisibleWhen('provider', ['b']);
    }

    public function getHiddenSecrets(): HiddenSecrets
    {
        return HiddenSecrets::KeepTyped;
    }
}
