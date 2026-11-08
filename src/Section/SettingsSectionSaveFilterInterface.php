<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;

/**
 * Optional interface of a {@see SettingsSectionInterface} (1.5): rewrites the values of a save before they are
 * normalized and validated, e.g. to lower-case colours or to drop a value equal to its runtime default (so the field
 * keeps following that default).
 *
 * Called by {@see InstanceSettingsInterface::save()} for every origin that goes through it (admin form, API, import).
 * The fallback seeder writes missing rows directly: a section implementing both interfaces applies the same rules in
 * its {@see SectionFallbackProviderInterface::getFallbacks()}. Must be stateless and must not read the store itself
 * (the current values are handed in).
 */
interface SettingsSectionSaveFilterInterface
{
    /**
     * The values to save. Keep the keys of `$values` (a key left out keeps its stored value; a null removes it).
     *
     * @param array<string, mixed> $values field name => submitted value, as handed to save()
     * @param array<string, bool|float|int|string|null> $current effective plain values of the section before the save
     *
     * @return array<string, mixed>
     */
    public function filterValues(array $values, array $current): array;
}
