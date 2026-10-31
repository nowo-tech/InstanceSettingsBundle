<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Attribute;

use Attribute;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;

/**
 * Optional class attribute of a {@see SettingsSectionInterface} service: sets the
 * tab order of the section in the admin UI and in exports (higher priority first). Sections are tagged by their
 * interface anyway; the attribute only adds the `priority` of the `nowo_instance_settings.section` tag.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSettingsSection
{
    /**
     * @param int $priority tab / export order (higher first, same as tagged-iterator priorities)
     */
    public function __construct(
        public int $priority = 0,
    ) {
    }
}
