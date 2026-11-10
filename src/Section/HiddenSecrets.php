<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;

/**
 * What a save does with a secret typed into a provider-dependent row hidden for the submitted controlling value (1.9,
 * `web_ui.hidden_secrets`, or per section with {@see SettingsSectionHiddenSecretsInterface}).
 *
 * - `drop` (default, as in 1.3–1.8): the typed value is ignored; only an explicit "clear" deletes the stored secret.
 * - `keep_typed`: the typed value is saved whichever provider is selected (typed without JavaScript, or before
 *   switching the provider back in the form). It is never validated as `required` (the row is hidden); its own
 *   constraints still run. "Clear" still deletes, blank still keeps.
 *
 * Only the admin form (bundle page and embed) is concerned: {@see InstanceSettingsInterface::save()}
 * writes whatever it is given.
 */
enum HiddenSecrets: string
{
    case Drop      = 'drop';
    case KeepTyped = 'keep_typed';
}
