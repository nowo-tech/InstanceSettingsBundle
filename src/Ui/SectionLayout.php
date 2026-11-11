<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Ui;

/**
 * How a section page lays out its field groups (1.14, `web_ui.section_layout`).
 */
enum SectionLayout: string
{
    /** Every field in one column, as before 1.14 (default; the markup is unchanged). */
    case Stacked = 'stacked';

    /** One UiKit subtab per field group (fewer than two groups: stacked); one form, Save saves every group. */
    case Tabs = 'tabs';
}
