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
- [E-mails (1.21)](#e-mails-121)
- [Bundle partials and assets](#bundle-partials-and-assets)
- [Translations](#translations)
- [Allowed exceptions](#allowed-exceptions)

## Pages

Every view model passes `bundle: 'NowoInstanceSettingsBundle'` (theme exception
`nowo_ui_kit.theme.bundles.NowoInstanceSettingsBundle`) and `layout: SettingsPages::layout()`. Bundle labels are
`NowoInstanceSettingsBundle` translation keys (`SettingsPages::label()`); section labels use each section's domain.

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Section (`section`) | `settings/section.html.twig` | `page/settings` | `SettingsPage` from `SettingsPageFactory::section()`: header `settings.title` + `settings.intro`, header action "Import / export" (`kind: export`, `data-testid="instance-settings-portability-link"`, only when the user may open it); one `SettingsSection` per section the user may open (`id` = section name, label / intro in the section domain, `href` = its page, `icon`), `current` = the open section, which carries the `SettingsSectionType` FormView, the submit label `settings.save` and the section's extra actions; root `data-testid="instance-settings-section"`, `data-section`. Without any section: `SettingsPageFactory::empty()` (one `SettingsSection` `empty` with `settings.empty_title` / `settings.empty`, `data-testid="instance-settings-empty"`) | none — `nowo_ui_settings_section` is the scaffold default (`_section_form`: `form_start`, REQ-TWIG-003 child loop, submit, actions, `form_end`); 1.14: with `section_groups` (`web_ui.section_layout: tabs`, `Ui\SectionGroupTabs`) the bundle block includes `settings/_section_form_tabs.html.twig` (one UiKit subtab per field group) instead of UiKit's `section_form` partial: override that template, not the partial, to change the tabs form; without UiKit's `nowo-ui-tabs.js` it falls back to stacked fieldsets; `section_groups` is reserved (a host context value never wins) |
| Import / export (`portability`) | `settings/portability.html.twig` | `page/settings` | `SettingsPage` from `SettingsPageFactory::portability()`: header `portability.title` + `portability.intro`, back link to the settings (`data-testid="instance-settings-back"`); sections `export` (`PortabilityExportType`, intro `portability.export.intro` / `intro_secrets`, submit `portability.export.submit`) and `import` (`PortabilityImportType`, multipart, intro `portability.import.intro`, submit `portability.import.submit`), stacked (no tabs); root `data-testid="instance-settings-portability"` | none (scaffold default) |

Other variables passed by the controller: `section` (current section name, section page) and, since 1.15,
`secret_reveal` (`Ui\SecretReveal`, or null): the Reveal control of the section's revealable secret rows, rendered by
`settings/_secret_reveal.html.twig` and set only for a user who may reveal. It carries the endpoint URL of each field
and the section's CSRF token, never a secret; a host section template that wants the control includes that partial
(`{{ include('@NowoInstanceSettingsBundle/settings/_secret_reveal.html.twig') }}`) and the bundle stylesheet. A page
that embeds two sections includes the partial once per section: each root names its section, every revealable row
carries the same name, and the script binds each row to the root of its own section, so two sections declaring the same
field name keep their own URLs, token and answers (audit F3). Since 1.17, `secret_lock` (bool): the form has rows locked
by `web_ui.reveal_in_input` (`data-nowo-instance-settings-locked="<field>"`, for every editor); when it is true and
there is no `secret_reveal` (an editor who may not reveal), include `settings/_secret_lock.html.twig`, which loads the
script that keeps those rows read-only until their "Replace the stored value" box is ticked.

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

## E-mails (1.21)

The default expiry notifier (`expiring_secrets.notify`) renders `expiry/notice.html.twig` and `expiry/notice.txt.twig`
(`notify.mail_template` without the suffix). Variables: `credential` (translated setting label), `expires_on`
(`YYYY-MM-DD`), `days_left`, `days_ago`, `expired`, `threshold`, `link` (absolute URL or null), `locale` and
`translation_domain`; texts `secret_expiry_notice.*`. Override them in
`templates/bundles/NowoInstanceSettingsBundle/expiry/` or point `notify.mail_template` at your own pair; never add a
secret. Self-contained HTML with inline styles (no UiKit page shell: mail clients do not load it).

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
