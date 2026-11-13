<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Probe;

use Nowo\InstanceSettingsBundle\Attribute\AsSettingsSection;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * Audit probe fixture: a second section declaring the same field name as RevealFixtureSection, write-only here — a
 * reveal in one section must never answer with the other section's secret (audit F3).
 */
#[AsSettingsSection(priority: 31)]
class OtherFixtureSection extends AbstractSettingsSection
{
    /**
     * Section name.
     */
    public function getName(): string
    {
        return 'oauth_other';
    }

    /**
     * Translation domain of the labels.
     */
    public function getTranslationDomain(): string
    {
        return 'app';
    }

    /**
     * The section's fields.
     */
    public function getFields(): iterable
    {
        yield SettingField::secret('client_secret', 'oauth.client_secret');
    }
}
