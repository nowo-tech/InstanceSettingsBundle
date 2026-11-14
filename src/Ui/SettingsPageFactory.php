<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Ui;

use Nowo\InstanceSettingsBundle\Form\SettingColorType;
use Nowo\InstanceSettingsBundle\Form\ThemeCardsType;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\UiKitBundle\Form\VisibleWhen;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\SettingsPage;
use Nowo\UiKitBundle\View\SettingsSection;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;

use function in_array;

/**
 * Builds the UiKit view models of the settings pages (REQ-UI-003): one settings scaffold page per section (tabs for
 * every section the user may open, the current section form) and the import / export page. The controller and the
 * page contract test share it. View models carry labels and FormViews only, never a secret. Stateless.
 */
final readonly class SettingsPageFactory
{
    /** Route of a section page (alias of {@see InstanceSettingsRoutes::SECTION}). */
    public const ROUTE_SECTION = InstanceSettingsRoutes::SECTION;

    /** Route of the import / export page (alias of {@see InstanceSettingsRoutes::PORTABILITY}). */
    public const ROUTE_PORTABILITY = InstanceSettingsRoutes::PORTABILITY;

    /** Route of the settings index (alias of {@see InstanceSettingsRoutes::INDEX}). */
    public const ROUTE_INDEX = InstanceSettingsRoutes::INDEX;

    /**
     * @param UrlGeneratorInterface $urlGenerator route URLs
     * @param SettingsPages $pages layout / template overrides
     * @param SectionNavigation $navigation `web_ui.section_icons` / `web_ui.navigation_hidden` (1.16)
     */
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private SettingsPages $pages = new SettingsPages(),
        private SectionNavigation $navigation = new SectionNavigation(),
    ) {
    }

    /**
     * Icon overrides and hidden sections of the bundle page (1.16).
     */
    public function navigation(): SectionNavigation
    {
        return $this->navigation;
    }

    /**
     * Template of a page (`web_ui.templates.<page>` override, else the bundle template).
     */
    public function template(string $page): string
    {
        return $this->pages->template($page);
    }

    /**
     * Section page: tabs for `$sections`, the form of `$current`.
     *
     * 1.16: a section listed in `web_ui.navigation_hidden` has no tab, except on its own page (opened by URL or by a
     * host link), where it stays in the bar as the current tab so its form renders. Icons follow
     * `web_ui.section_icons`, else the section's own.
     *
     * @param array<string, SettingsSectionInterface> $sections sections the user may open (name => section)
     * @param iterable<Action> $actions extra actions of the current section
     * @param bool $portability show the "Import / export" header action
     */
    public function section(array $sections, string $current, FormView $form, iterable $actions = [], bool $portability = false): SettingsPage
    {
        $tabs = [];
        foreach ($sections as $name => $section) {
            if ($name !== $current && $this->navigation->isHidden($name)) {
                continue;
            }
            $domain = $section->getTranslationDomain();
            $intro  = $section->getIntro();
            $tabs[] = new SettingsSection(
                id: $name,
                label: new Label($section->getLabel(), $domain),
                href: $this->urlGenerator->generate(self::ROUTE_SECTION, ['section' => $name]),
                form: $name === $current ? $form : null,
                intro: $this->intro($intro, $domain),
                submitLabel: SettingsPages::label('settings.save'),
                formThemes: $name === $current ? self::formThemes($form) : [],
                actions: $name === $current ? $actions : [],
                icon: $this->navigation->icon($name, $section),
            );
        }

        return new SettingsPage(
            header: $this->header($portability),
            sections: $tabs,
            current: $current,
            bundle: SettingsPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'instance-settings-section', 'data-section' => $current] + (self::hasVisibleWhenRows($form) ? VisibleWhen::containerAttributes() : []),
        );
    }

    /**
     * Section page embedded in a host route (1.6, IS-1): tabs only for `$tabs` (the allowlisted sections the user may
     * open, linked to the host routes), the form of `$current`, the host header (else the bundle title and intro,
     * without the import / export link) and the host layout (else `web_ui.layout_template`).
     *
     * `web_ui.navigation_hidden` does not apply here (the embed's own allowlist chooses its tabs); `web_ui.section_icons`
     * does (1.16).
     *
     * @param array<string, array{section: SettingsSectionInterface, href: string}> $tabs section name => section and host URL
     * @param iterable<Action> $actions extra actions of the current section
     */
    public function embeddedSection(array $tabs, string $current, FormView $form, iterable $actions = [], ?PageHeader $header = null, ?string $layout = null): SettingsPage
    {
        $sections = [];
        foreach ($tabs as $name => $tab) {
            $section    = $tab['section'];
            $domain     = $section->getTranslationDomain();
            $sections[] = new SettingsSection(
                id: $name,
                label: new Label($section->getLabel(), $domain),
                href: $tab['href'],
                form: $name === $current ? $form : null,
                intro: $this->intro($section->getIntro(), $domain),
                submitLabel: SettingsPages::label('settings.save'),
                formThemes: $name === $current ? self::formThemes($form) : [],
                actions: $name === $current ? $actions : [],
                icon: $this->navigation->icon($name, $section),
            );
        }

        return new SettingsPage(
            header: $header ?? $this->header(false),
            sections: $sections,
            current: $current,
            bundle: SettingsPages::BUNDLE,
            layout: $layout ?? $this->pages->layout(),
            attributes: ['data-testid' => 'instance-settings-section', 'data-section' => $current, 'data-embedded' => 'true'] + (self::hasVisibleWhenRows($form) ? VisibleWhen::containerAttributes() : []),
        );
    }

    /**
     * Form themes of a section form: the theme-card picker's theme when a row uses {@see ThemeCardsType} (1.6), the
     * colour input's theme when a row uses {@see SettingColorType} (1.12); nothing otherwise (pages without such a row
     * render unchanged).
     *
     * @return list<string>
     */
    public static function formThemes(FormView $form): array
    {
        $themes = [];
        foreach ([ThemeCardsType::BLOCK_PREFIX => ThemeCardsType::FORM_THEME, SettingColorType::BLOCK_PREFIX => SettingColorType::FORM_THEME] as $prefix => $theme) {
            if (self::hasRowOf($form, $prefix)) {
                $themes[] = $theme;
            }
        }

        return $themes;
    }

    /**
     * Whether the form has a colour row (1.12): the page then loads the colour script and the bundle stylesheet.
     */
    public static function hasColorRows(FormView $form): bool
    {
        return self::hasRowOf($form, SettingColorType::BLOCK_PREFIX);
    }

    /**
     * Whether a direct child of the form has `$blockPrefix` among its block prefixes.
     */
    private static function hasRowOf(FormView $form, string $blockPrefix): bool
    {
        foreach ($form->children as $child) {
            if (in_array($blockPrefix, $child->vars['block_prefixes'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a row of the form carries the UiKit `visible-when` attributes (1.3): the page root then connects the
     * peer (`data-controller="visible-when"`); other pages render unchanged.
     */
    public static function hasVisibleWhenRows(FormView $form): bool
    {
        foreach ($form->children as $child) {
            if (isset($child->vars['row_attr'][VisibleWhen::FIELD_ATTRIBUTE])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Index without any registered (or accessible) section.
     */
    public function empty(bool $portability = false): SettingsPage
    {
        return new SettingsPage(
            header: new PageHeader(
                SettingsPages::label('settings.title'),
                SettingsPages::label('settings.intro'),
                $portability ? [$this->portabilityAction()] : [],
            ),
            sections: [new SettingsSection(id: 'empty', label: SettingsPages::label('settings.empty_title'), intro: SettingsPages::label('settings.empty'))],
            bundle: SettingsPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'instance-settings-empty'],
        );
    }

    /**
     * Import / export page: the export form and the import form, stacked.
     */
    public function portability(FormView $export, FormView $import, bool $secretsAvailable): SettingsPage
    {
        return new SettingsPage(
            header: new PageHeader(
                SettingsPages::label('portability.title'),
                SettingsPages::label('portability.intro'),
                back: new BackLink($this->urlGenerator->generate(self::ROUTE_INDEX), SettingsPages::label('portability.back'), 'instance-settings-back'),
            ),
            sections: [
                new SettingsSection(
                    id: 'export',
                    label: SettingsPages::label('portability.export.title'),
                    form: $export,
                    intro: SettingsPages::label($secretsAvailable ? 'portability.export.intro_secrets' : 'portability.export.intro'),
                    submitLabel: SettingsPages::label('portability.export.submit'),
                ),
                new SettingsSection(
                    id: 'import',
                    label: SettingsPages::label('portability.import.title'),
                    form: $import,
                    intro: SettingsPages::label('portability.import.intro'),
                    submitLabel: SettingsPages::label('portability.import.submit'),
                ),
            ],
            bundle: SettingsPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'instance-settings-portability'],
        );
    }

    /**
     * UiKit labels of the notices of a section ({@see SettingsSectionNoticesInterface}), in the section domain.
     *
     * @param iterable<Label|string|TranslatableMessage> $notices
     *
     * @return list<Label>
     */
    public function notices(iterable $notices, string $domain): array
    {
        $labels = [];
        foreach ($notices as $notice) {
            $labels[] = $this->label($notice, $domain);
        }

        return $labels;
    }

    /**
     * UiKit label of a section intro (string key in the section domain, Label as is, TranslatableMessage mapped).
     */
    private function intro(Label|TranslatableMessage|string|null $intro, string $domain): ?Label
    {
        return $intro === null ? null : $this->label($intro, $domain);
    }

    /**
     * UiKit label of a section text (string key in the section domain, Label as is, TranslatableMessage mapped).
     */
    private function label(Label|TranslatableMessage|string $text, string $domain): Label
    {
        if ($text instanceof Label) {
            return $text;
        }
        if ($text instanceof TranslatableMessage) {
            return new Label($text->getMessage(), $text->getDomain() ?? $domain, $text->getParameters());
        }

        return new Label($text, $domain);
    }

    private function header(bool $portability): PageHeader
    {
        return new PageHeader(
            SettingsPages::label('settings.title'),
            SettingsPages::label('settings.intro'),
            $portability ? [$this->portabilityAction()] : [],
        );
    }

    private function portabilityAction(): Action
    {
        return new Action(
            kind: 'export',
            label: SettingsPages::label('settings.portability'),
            href: $this->urlGenerator->generate(self::ROUTE_PORTABILITY),
            attributes: ['data-testid' => 'instance-settings-portability-link'],
        );
    }
}
