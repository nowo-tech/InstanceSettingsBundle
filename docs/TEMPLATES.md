# Templates

Pages of InstanceSettingsBundle on the UiKit page contract (REQ-UI-003, UiKit 2.17+). The admin UI
(`web_ui.path_prefix`, default `/admin/settings`, enabled with `web_ui.enabled: true`) has two page templates.
`Nowo\InstanceSettingsBundle\Ui\SettingsPageFactory` builds the UiKit view models (`Nowo\UiKitBundle\View\*`) for the
controller; `Nowo\InstanceSettingsBundle\Ui\SettingsPages` resolves the layout (`web_ui.layout_template`, null =
`nowo_ui_kit.layouts.admin`) and the per-page overrides (`web_ui.templates.<page>`). Both pages extend the UiKit
settings scaffold and fill no block of their own. Contract test: `tests/Integration/Contract/PageContractTest.php`
(UiKit `PageContractTestCase`, strict missing-translation rule). Twig namespace: `@NowoInstanceSettingsBundle` (views in
`src/Resources/views/`).

## Table of contents

- [Pages](#pages)
- [Secrets in the pages](#secrets-in-the-pages)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Bundle partials and assets](#bundle-partials-and-assets)
- [Translations](#translations)
- [Allowed exceptions](#allowed-exceptions)

## Pages

Every view model passes `bundle: 'NowoInstanceSettingsBundle'` (theme exception
`nowo_ui_kit.theme.bundles.NowoInstanceSettingsBundle`) and `layout: SettingsPages::layout()`. Bundle labels are
`NowoInstanceSettingsBundle` translation keys (`SettingsPages::label()`); section labels use each section's domain.

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Section (`section`) | `settings/section.html.twig` | `page/settings` | `SettingsPage` from `SettingsPageFactory::section()`: header `settings.title` + `settings.intro`, header action "Import / export" (`kind: export`, `data-testid="instance-settings-portability-link"`, only when the user may open it); one `SettingsSection` per section the user may open (`id` = section name, label / intro in the section domain, `href` = its page, `icon`), `current` = the open section, which carries the `SettingsSectionType` FormView, the submit label `settings.save` and the section's extra actions; root `data-testid="instance-settings-section"`, `data-section`. Without any section: `SettingsPageFactory::empty()` (one `SettingsSection` `empty` with `settings.empty_title` / `settings.empty`, `data-testid="instance-settings-empty"`) | none — `nowo_ui_settings_section` is the scaffold default (`_section_form`: `form_start`, REQ-TWIG-003 child loop, submit, actions, `form_end`) |
| Import / export (`portability`) | `settings/portability.html.twig` | `page/settings` | `SettingsPage` from `SettingsPageFactory::portability()`: header `portability.title` + `portability.intro`, back link to the settings (`data-testid="instance-settings-back"`); sections `export` (`PortabilityExportType`, intro `portability.export.intro` / `intro_secrets`, submit `portability.export.submit`) and `import` (`PortabilityImportType`, multipart, intro `portability.import.intro`, submit `portability.import.submit`), stacked (no tabs); root `data-testid="instance-settings-portability"` | none (scaffold default) |

Other variables passed by the controller: `section` (current section name, section page).

## Secrets in the pages

- The section form never receives a stored secret: `SettingsSectionType` hands DoctrineEncrypt's `SecretType` only the
  placeholder `stored` as `current_value` (with `hint_visible_last: 0` the hint is a bare mask), the input is a
  password field rendered empty (`always_empty`), a blank submission keeps the secret and the "Remove the saved
  value" checkbox clears it.
- `PageContractTest::testPageNeverPrintsAStoredSecret` renders every page with a stored secret and asserts it never
  appears; `AdminUiTest` asserts the same on the real pages.

## Unique pages

- (none) — both pages extend the UiKit settings scaffold.

## Per-page overrides

`web_ui.templates.section` / `web_ui.templates.portability` point at a host template that extends the bundle page and
overrides one block, e.g. a custom rendering of one section:

```twig
{# templates/admin/settings_section.html.twig — web_ui.templates.section #}
{% extends '@!NowoInstanceSettingsBundle/settings/section.html.twig' %}

{% block nowo_ui_settings_section %}
    {% if section.id == 'mailer' %}
        <p class="nowo-ui-muted">{{ 'mailer.hint'|trans({}, 'messages') }}</p>
    {% endif %}
    {{ parent() }}
{% endblock %}
```

## Overriding templates (REQ-TWIG-001)

See [USAGE.md — Overriding templates](USAGE.md#overriding-templates-req-twig-001) for the freeze rule, the recovery
procedure and the `<subpath>` table (`settings/section.html.twig`, `settings/portability.html.twig`). Prefer
`nowo_ui_kit.layouts.admin`, the UiKit theme and `web_ui.templates.<page>` over full-file forks.

## Bundle partials and assets

None: the pages use UiKit partials only (tabs, card, section form, flashes) and add no CSS / JS (no
`nowo_ui_styles` / `nowo_ui_scripts` override, no asset package).

## Translations

`NowoInstanceSettingsBundle` (`settings.*`, `flash.*`, `portability.*`) in `en`, `es`, `fr`, `de`, `it`, `pt`, `nl`; the
secret widget copy comes from `NowoDoctrineEncryptBundle`. Section labels belong to each section's domain. The contract
test loads the bundle catalogues, the DoctrineEncrypt catalogue and the test fixture catalogue (`app`) with the strict
missing-translation rule.

## Allowed exceptions

- (none)
