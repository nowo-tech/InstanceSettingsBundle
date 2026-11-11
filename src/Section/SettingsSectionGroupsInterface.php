<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

/**
 * Optional interface of a {@see SettingsSectionInterface} (1.14): the field groups of a long section, shown as subtabs
 * when `web_ui.section_layout` is `tabs` (one form: Save still saves every group).
 *
 * A separate interface, so existing sections keep compiling. A section without it gets its groups from the 1.5 form
 * groups of its fields ({@see SettingField::withGroup()}): one tab per distinct group, labelled `<section>.group.<code>`.
 * A section with fewer than two non-empty groups always renders stacked.
 *
 * Implementations must be stateless (worker-safe): build the groups from the section's own configuration, never from
 * request data.
 */
interface SettingsSectionGroupsInterface
{
    /**
     * Groups in tab order. Each field goes to the first group that lists its name, else to the first group that claims
     * its form group, else to the {@see SettingsGroup::$rest} group (an automatic "Other" group without one). Empty
     * groups are not shown.
     *
     * @return iterable<SettingsGroup>
     */
    public function getGroups(): iterable;
}
