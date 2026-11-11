<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Ui;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Section\SectionGroups;
use Nowo\InstanceSettingsBundle\Section\SettingsGroup;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Form\FormView;

use function count;
use function http_build_query;

/**
 * The subtabs of a section page (1.14, `web_ui.section_layout: tabs`): one per resolved field group, rendered by
 * `settings/_section_form_tabs.html.twig` with UiKit's in-page tabs runtime (`nowo-ui-tabs.js`).
 *
 * The open tab is the first one with a validation error, else the requested `?group=` code when the section has it,
 * else the first. Built per page from the form view; immutable and never kept between requests.
 */
final readonly class SectionGroupTabs
{
    /** Query parameter of the open tab. */
    public const QUERY_PARAMETER = 'group';

    /**
     * @param string $id DOM id of the tabs root (`nowo-instance-settings-<section>-groups`)
     * @param string $active code of the open tab
     * @param list<SectionGroupTab> $tabs tabs in order (at least two)
     */
    public function __construct(
        public string $id,
        public string $active,
        public array $tabs,
    ) {
        if (count($tabs) < 2) {
            throw new InvalidArgumentException('SectionGroupTabs needs at least two tabs.');
        }
    }

    /**
     * Tabs of a section form.
     *
     * @param string $section section name
     * @param list<SettingsGroup> $groups resolved groups ({@see SectionGroups::resolve()}, at least two)
     * @param string $domain section translation domain (labels without their own domain)
     * @param FormView $form the section form view (error counts)
     * @param string|null $requested `?group=` of the request (ignored unless the section has that group)
     */
    public static function build(string $section, array $groups, string $domain, FormView $form, ?string $requested): self
    {
        $id      = 'nowo-instance-settings-' . $section . '-groups';
        $errors  = [];
        $active  = null;
        $invalid = null;
        foreach ($groups as $group) {
            $count = 0;
            foreach ($group->fields as $name) {
                $child = $form->children[$name] ?? null;
                if ($child instanceof FormView && ($child->vars['valid'] ?? true) === false) {
                    ++$count;
                }
            }
            $errors[$group->code] = $count;
            $invalid ??= $count > 0 ? $group->code : null;
            $active ??= $group->code === $requested ? $group->code : null;
        }
        $active = $invalid ?? $active ?? $groups[0]->code;

        $tabs = [];
        foreach ($groups as $group) {
            $tabs[] = new SectionGroupTab(
                $group->code,
                $id . '-' . $group->code,
                new Label($group->labelKey($section), $group->translationDomain ?? $domain),
                $group->fields,
                $errors[$group->code],
                $group->code === $active,
                '?' . http_build_query([self::QUERY_PARAMETER => $group->code]),
            );
        }

        return new self($id, $active, $tabs);
    }

    /**
     * Whether the section has a group of this code.
     */
    public function has(?string $code): bool
    {
        foreach ($this->tabs as $tab) {
            if ($tab->code === $code) {
                return true;
            }
        }

        return false;
    }
}
