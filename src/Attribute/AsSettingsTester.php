<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Attribute;

use Attribute;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\SectionTest\SettingsSectionTesterInterface;

/**
 * Registers a {@see SettingsSectionTesterInterface} service as the tester of a settings section, or of one field group
 * of it (1.18): the section page then shows a Test button beside Save (on the group's subtab for a group tester).
 *
 * At most one tester per section and group. The section must be registered and the group must be one of its field
 * groups (declared groups, or the 1.5 form groups of its fields); the container fails on first use otherwise.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSettingsTester
{
    /**
     * @param string $section section name ({@see SettingsSectionRegistry::NAME_PATTERN})
     * @param string|null $group field group code (null = the whole section)
     */
    public function __construct(
        public string $section,
        public ?string $group = null,
    ) {
    }
}
