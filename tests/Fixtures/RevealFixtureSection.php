<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Attribute\AsSettingsSection;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * 1.15: a section with one revealable secret (an OAuth client secret the admin has to read elsewhere) next to a secret
 * that stays write-only (a webhook signing key) and a plain field.
 */
#[AsSettingsSection(priority: 30)]
class RevealFixtureSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'oauth';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getFields(): iterable
    {
        yield SettingField::string('client_id', null, 'oauth.client_id');
        yield SettingField::secret('client_secret', 'oauth.client_secret', revealable: true);
        yield SettingField::secret('signing_key', 'oauth.signing_key');
    }
}
