<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Ui;

use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\SettingsPage;
use Nowo\UiKitBundle\View\SettingsSection;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
     */
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private SettingsPages $pages = new SettingsPages(),
    ) {
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
     * @param array<string, SettingsSectionInterface> $sections sections the user may open (name => section)
     * @param iterable<Action> $actions extra actions of the current section
     * @param bool $portability show the "Import / export" header action
     */
    public function section(array $sections, string $current, FormView $form, iterable $actions = [], bool $portability = false): SettingsPage
    {
        $tabs = [];
        foreach ($sections as $name => $section) {
            $domain = $section->getTranslationDomain();
            $intro  = $section->getIntro();
            $tabs[] = new SettingsSection(
                id: $name,
                label: new Label($section->getLabel(), $domain),
                href: $this->urlGenerator->generate(self::ROUTE_SECTION, ['section' => $name]),
                form: $name === $current ? $form : null,
                intro: $intro === null ? null : new Label($intro, $domain),
                submitLabel: SettingsPages::label('settings.save'),
                actions: $name === $current ? $actions : [],
                icon: $section->getIcon(),
            );
        }

        return new SettingsPage(
            header: $this->header($portability),
            sections: $tabs,
            current: $current,
            bundle: SettingsPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'instance-settings-section', 'data-section' => $current],
        );
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
