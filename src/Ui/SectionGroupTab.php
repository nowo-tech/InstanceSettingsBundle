<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Ui;

use Nowo\UiKitBundle\View\Label;

/**
 * One subtab of a section page (1.14): its code, label, fields and error count. Built per page by
 * {@see SectionGroupTabs::build()}; immutable.
 */
final readonly class SectionGroupTab
{
    /**
     * @param string $code group code (the `?settings_group=` value)
     * @param string $tabId tab id of UiKit's tabs runtime (`data-nowo-ui-tab-id`); the button id is `tab-<tabId>` and
     *                      the panel id `tab-<tabId>-panel`
     * @param Label $label tab label
     * @param list<string> $fields form children of the panel, in field order
     * @param int $errors fields of the panel with a validation error (badge)
     * @param bool $active the panel open on load
     * @param string $href `?<parameter>=<code>` (relative link to this tab)
     */
    public function __construct(
        public string $code,
        public string $tabId,
        public Label $label,
        public array $fields,
        public int $errors,
        public bool $active,
        public string $href,
    ) {
    }

    /**
     * Whether a field of the panel has a validation error.
     */
    public function isInvalid(): bool
    {
        return $this->errors > 0;
    }
}
