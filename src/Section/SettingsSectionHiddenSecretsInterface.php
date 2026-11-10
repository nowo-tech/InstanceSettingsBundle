<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

/**
 * Optional interface of a {@see SettingsSectionInterface} (1.9): overrides the bundle-wide `web_ui.hidden_secrets`
 * for this section ({@see HiddenSecrets}). Must be stateless (return a constant or a value fixed at construction).
 */
interface SettingsSectionHiddenSecretsInterface
{
    /**
     * What the admin form does with a secret typed into a row hidden for the submitted provider.
     */
    public function getHiddenSecrets(): HiddenSecrets;
}
