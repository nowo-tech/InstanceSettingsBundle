<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Ui;

use Nowo\UiKitBundle\View\Label;

use function str_replace;

/**
 * One Test button of a section page (1.18): the whole section (`group` null) or one field group, with the label of that
 * group and the last stored report shown under the button.
 */
final readonly class SectionTestButton
{
    /**
     * @param string|null $group group code (null = the whole section)
     * @param Label|null $groupLabel label of the group (null for the section tester)
     * @param array{section: string, group: string|null, status: string, statusLabel: string, testedAt: string, time: string, summary: string, checks: list<array{status: string, statusLabel: string, label: string, hint: string|null}>}|null $last
     *                                                                                                                                                                                                                                                the last report, translated ({@see SectionTestView::json()}; null = never tested)
     */
    public function __construct(
        public ?string $group,
        public ?Label $groupLabel,
        public ?array $last,
    ) {
    }

    /**
     * DOM-safe suffix of the button's ids (`section` or the group code with dots as dashes).
     */
    public function idSuffix(): string
    {
        return $this->group === null ? 'section' : 'group-' . str_replace('.', '-', $this->group);
    }
}
