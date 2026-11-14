# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.16.0](#to-1160)
- [To 1.15.1](#to-1151)
- [To 1.15.0](#to-1150)
- [To 1.14.1](#to-1141)
- [To 1.14.0](#to-1140)
- [To 1.14.0](#to-1140-1)
- [To 1.13.1](#to-1131)
- [To 1.13.0](#to-1130)
- [To 1.12.0](#to-1120)
- [To 1.11.1](#to-1111)
- [To 1.11.0](#to-1110)
- [To 1.10.0](#to-1100)
- [To 1.10.0](#to-1100-1)
- [To 1.9.0](#to-190)
- [To 1.8.0](#to-180)
- [To 1.7.1](#to-171)
- [To 1.7.0](#to-170)
- [To 1.6.0](#to-160)
- [To 1.5.0](#to-150)
- [To 1.4.0](#to-140)
- [To 1.3.1](#to-131)
- [To 1.3.0](#to-130)
- [To 1.2.0](#to-120)
  - [To 1.2.0](#to-120-1)
- [To 1.1.1](#to-111)
- [To 1.1.0](#to-110)
- [To 1.0.0](#to-100)
  - [Requirements](#requirements)
  - [Enable and configure](#enable-and-configure)
  - [Migrating from an in-app settings entity](#migrating-from-an-in-app-settings-entity)
  - [Template contract](#template-contract)
  - [Breaking changes](#breaking-changes)

## Unreleased

## To 1.16.0

Nothing to do: every new option defaults to the behaviour of 1.15.

- **Tab icons of the bundle's sections.** `brand` now declares `tabler:palette` and `appearance`
  `tabler:layout-dashboard`. With the `tabler-icons` or `ux_icon` icon set (and a `data-tab-style` that shows icons)
  their tabs gain an icon; with `bootstrap-icons` or `none` they render as before. To change or drop the look, set
  `web_ui.section_icons.brand` / `.appearance` (an icon string; the tab cannot be set back to "no icon" from config).
- **`web_ui.section_icons`** (new, default `{}`): section name => icon, overriding or filling any section's
  `getIcon()`. Values must be a canonical UiKit kind or a `prefix:name` icon; names must be lower snake case.
- **`web_ui.navigation_hidden`** (new, default `[]`): sections left out of the bundle page's tab bar and of the
  "first section" redirect. Presentation only — access, exports, embeds and the section's own URL are unchanged. A
  host that hid a section by denying its area to everybody (`area_roles`) only to keep it out of the tab bar can
  now use this instead; one that denies it for real must keep `area_roles`. Example (Symfony Beacon):
  `navigation_hidden: [brand, appearance]`.
- Unknown section names in both options are ignored with a `debug` log line, never an error.
- **`web_ui.reveal_on_toggle`** (new, default `false`) and **`web_ui.reveal_toggle_only`** (default `false`): the
  PasswordToggle eye of a revealable secret row reveals the stored secret through the existing reveal endpoint; see
  [CONFIGURATION](CONFIGURATION.md#revealing-from-the-password-eye-116). Run `assets:install` so the updated
  `nowo-instance-settings-reveal.js` is served. Two new translation keys (`reveal.toggle_show`, `reveal.toggle_hide`)
  exist in the seven bundled locales; override them in your translations if needed.
- **PHP API.** `SettingsPageFactory` takes an optional third argument `SectionNavigation` and has `navigation()`;
  `SecretReveal` takes optional `onToggle` / `toggleOnly`; `SettingsSectionEmbedder` takes two optional trailing
  booleans. Code constructing them by hand keeps working.

## To 1.15.1

From **1.15.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Target 1.15.1, from **1.15.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Nothing to change in the configuration. What you may observe (audit N2 / N3):

1. **`Instance settings: secret reveal refused` with `reason: ui_gate` now names the actor** (the user identifier, as
   every other reveal line since 1.15) instead of `null`. Alerts grouping refusals per actor start to group these.
2. **A section or field name the bundle does not declare is logged as `key: unknown`.** Alerts or dashboards that
   matched on a specific undeclared key (an attacker-chosen one) will not see it any more; declared names are
   unchanged.
3. **Refusal lines are throttled per caller.** With `security.reveal_rate_limiter` set, a caller writes as many
   `warning` lines per window as the limiter allows (in a bucket of its own: refusals never spend reveals), then
   `debug` lines. A "burst of refusals" alert therefore fires on the first N refusals per caller and window, not on
   every one; keep `debug` in the logs you search if you need every attempt. Without a limiter, the line of a caller
   the host cannot name is `debug`.
4. Code that builds `InstanceSettingsAccessListener` or `SecretRevealer` by hand keeps working (one new optional
   constructor argument each, last).

## To 1.15.0

From **1.14.1**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Nothing to do: the secret reveal is off until a host asks for it.

To use it (owner decision of 2026-11-13):

1. Declare the secret fields whose stored value an admin must be able to read back — and only those:

   ```php
   yield SettingField::secret('client_secret', 'oauth.client_secret', revealable: true);
   yield SettingField::secret('signing_key', 'oauth.signing_key');   // unchanged: write-only
   ```

2. Say who may reveal. The default is `ROLE_DEVELOPER`, asked **on top of** the section's own area; the roles never
   fall back to `access_roles`, and `security.area_roles._reveal` is now refused at compile time (use
   `security.reveal_roles`). `security.reveal_roles: []` refuses every reveal, whatever the fields declare:

   ```yaml
   nowo_instance_settings:
       security:
           reveal_roles: [ROLE_DEVELOPER]
   ```

3. **Rate-limit it — this step is required** as soon as any field declares `revealable: true` (owner decision of
   2026-11-13). Install `symfony/rate-limiter`, declare a limiter and point the bundle at it:

   ```bash
   composer require symfony/rate-limiter
   ```

   ```yaml
   nowo_instance_settings:
       security:
           reveal_rate_limiter: limiter.instance_settings_reveal
   framework:
       rate_limiter:
           instance_settings_reveal: { policy: fixed_window, limit: 10, interval: '1 hour' }
   ```

   Without it the section definitions refuse to load (router warm-up, so `cache:warmup` fails in the build, else the
   first request) with a message naming the field and the option. **This takes down every consumer of the section
   definitions, not just the reveal:** the whole admin UI, `InstanceSettingsInterface` reads anywhere in the
   application, the seed / import / export commands and every host embed fail with the same error until the limiter is
   set (audit N6). Its two ways out: drop `revealable: true`, or set
   `security.reveal_roles: []` (the kill switch — with reveals switched off a missing limiter blocks nothing).
   Hosts that declare **no** revealable field are untouched: no limiter, no new dependency, no behaviour change.

4. **Exclude the reveal answer from every response-body log.** The answer carries the plaintext in a JSON body, and
   `no-store` only instructs clients. With our own HttpLogBundle installed, JSON response bodies are captured by
   default and `value` is not among the redacted paths, so the secret would land in the `http_log` table in clear for
   its retention, readable by whoever may read HTTP logs:

   ```yaml
   # config/packages/nowo_http_log.yaml
   nowo_http_log:
       ignore_path_prefixes: ['/admin/http-log', '/admin/settings/sections']
       redaction:
           json_paths: ['*.password', '*._password', '*.token', '*._token', '*._csrf_token', '*.value']
   ```

   Do the same in any reverse proxy, WAF or APM agent that records response bodies.

5. `php bin/console assets:install` (the page loads the new `nowo-instance-settings-reveal.js`), and if your Content
   Security Policy lists script sources, the script is served from `bundles/nowoinstancesettings/js/` like the colour
   and tabs scripts and is loaded with the request `csp_nonce`.

What you accept with `revealable: true`: the plaintext reaches the admin's browser, where screenshots, shoulder
surfing and pasting into the wrong window are possible. The reveal is explicit and audited (`SecretRevealedEvent`, log
line: field, actor, time, never the value) but none of that is undone by the audit trail — see
[`CONFIGURATION.md`](CONFIGURATION.md#revealing-a-stored-secret-115).

New host-visible API: `SettingField::secret(..., revealable: true)`, `InstanceSettingsRoutes::REVEAL`,
`InstanceSettingsAccessCheckerInterface::AREA_REVEAL`, `Event\SecretRevealedEvent`, `Secret\SecretRevealer`
(public service) and the `revealable_secrets` option of `SettingsSectionType`. A custom
`SettingsSectionInterface` implementation needs no change.

## To 1.14.1

From **1.14.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

## To 1.14.0

From **1.13.1**:

```bash
composer update nowo-tech/instance-settings-bundle
```

## To 1.14.0

From **1.13.1**:

```bash
composer update nowo-tech/instance-settings-bundle
php bin/console assets:install
```

To **1.14.0** (minor): nothing changes until you opt in. The default `web_ui.section_layout: stacked` renders the same
markup as 1.13.

To show long sections as subtabs:

```yaml
# config/packages/nowo_instance_settings.yaml
nowo_instance_settings:
    web_ui:
        section_layout: tabs
```

- Run `assets:install` (new `bundles/nowoinstancesettings/js/nowo-instance-settings-tabs.js`).
- Your layout must load UiKit's `tabs` runtime: UiKit's document layout and the `scripts` partial
  (`{{ include(nowo_ui_partial('scripts')) }}`) do by default. Without it every group stays visible, stacked.
- The bundle's `brand` and `appearance` sections declare their groups. Your own sections: implement
  `SettingsSectionGroupsInterface` (see [USAGE](USAGE.md#field-groups-and-subtabs-114)), or rely on the 1.5
  `SettingField::withGroup()` groups (labels `<section>.group.<code>` in the section domain).
- The tabs layout renders `settings/_section_form_tabs.html.twig` instead of UiKit's `section_form` theme partial: a
  host override of `section_form` still applies in the stacked layout only. To change the tabs form, override
  `templates/bundles/NowoInstanceSettingsBundle/settings/_section_form_tabs.html.twig`. A host template for
  `web_ui.templates.section` that does not extend the bundle template must include that partial when the
  `section_groups` variable is set. `section_groups` is reserved: a `withContext()` value of that name is ignored.
- Without UiKit's `nowo-ui-tabs.js` on the page the tabs layout falls back to the stacked fieldsets (every group
  visible, the tab list hidden); the form still saves every group.
- Declared groups are validated when the section definitions load, in every layout (at most one `rest` group).
- Embeds follow the config; `SectionEmbedOptions::withSectionLayout('tabs')` opts one embed in (or out).
- Links may carry `?settings_group=<code>` to open a tab (e.g. `/developer/appearance/brand?settings_group=logos`).
  The name is `web_ui.section_group_parameter` (default `settings_group`), or `SectionEmbedOptions::withGroupParameter()`
  per embed; a host `?group=` is left alone.
- With the filesystem store, schedule `nowo:instance-settings:purge-files` (e.g. daily): a transaction dropped by a
  connection closed mid-transaction leaves its `.tmp-*` files until the next purge.

## To 1.13.1

From **1.13.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

To **1.13.1** (patch): `composer update nowo-tech/instance-settings-bundle`; no configuration change. The bundle now
registers `Nowo\InstanceSettingsBundle\EventListener\WorkerBoundaryResetListener`, which clears the settings memo
and drops the bundle's private entity manager at every request, Messenger message and console command boundary, so a
`messenger:consume --no-reset` consumer or a long-running command reads values saved by another process from its next
unit of work. Nothing to do unless you relied on a consumer keeping the values it read first (re-read them per
message instead).

## To 1.13.0

From **1.12.x**: `composer update nowo-tech/instance-settings-bundle`, then `bin/console assets:install` (the bundle
now also ships `public/css/nowo-instance-settings.unlayered.css`). No configuration change is required.

**Optional — error page illustrations from the brand** (needs `brand.enabled`, `files.store: filesystem` and UiKit
≥ 2.47). Declare one extra image per status you want to illustrate and, optionally, a base image, then point UiKit at
the bundle's service:

```yaml
# config/packages/nowo_instance_settings.yaml
nowo_instance_settings:
    brand:
        extra_images:
            mascot:    { default: brand/mascot.png }
            error_404: { default: illustrations/error-404.png }
            error_503: { default: illustrations/error-503.png }
        error_illustrations:
            base: mascot
            per_code: true

# config/packages/nowo_ui_kit.yaml
nowo_ui_kit:
    error_pages:
        illustration_provider: nowo_instance_settings.brand.error_illustration_provider
        illustration_provider_budget_ms: 20
```

For status N UiKit then shows the uploaded `error_<N>` image, else the uploaded base image, else its own
`error_pages.illustrations` map: until something is uploaded the pages are unchanged. Configured `default` assets never
replace the map. Keep the file route `PUBLIC_ACCESS` line (anonymous visitors load the image). With
`files.store: doctrine` the service does not exist and a `base` fails the build. See
[CONFIGURATION](CONFIGURATION.md#error-page-illustrations-113) and
[USAGE](USAGE.md#error-page-illustrations-113).

**Bundle stylesheet in UiKit's cascade layer** (audit LM29-I1). `nowo-instance-settings.css` (colour input, image cards)
now sits in `@layer nowo-ui`, like UiKit's `nowo-ui.css`: host utilities (Tailwind `components` / `utilities`) and
unlayered host CSS win over it, where 1.12 won over every layered rule. Nothing to do on a host that links UiKit's
layered `nowo-ui.css`. A host that links UiKit's `nowo-ui.unlayered.css` (Bootstrap or another unlayered framework)
sets `web_ui.unlayered_styles: true` to keep the 1.12 cascade (`nowo-instance-settings.unlayered.css`); host templates
that include `settings/_styles.html.twig` get the page variable, or pass `unlayered: true`.

**`image_input: false`** (audit LM29-I3): an image field with `withFormOptions(['image_input' => false])` now keeps its
pre-1.12 row (label and help on the row, no card) and the page no longer loads UiKit's `image-input` script or the
bundle stylesheet for it when no other field needs them. Fields with the image input box are unchanged.

**Brand appearance colours** (audit LM-L3). A colour re-submitted equal to its stored value is now kept even when it
equals the preset (the 1.12 rule of colour fields): an untouched save, or switching `preset` / `preset_dark` to a
preset whose colour matches a stored colour, no longer deletes the stored row. A colour changed to the preset is still
saved as null (it follows the preset). To follow the preset again, empty the field ("Use the preset colour").

## To 1.12.0

From **1.11.x**: `composer update nowo-tech/instance-settings-bundle`, then `bin/console assets:install` (the bundle now
ships `public/js/nowo-instance-settings-color.js` and `public/css/nowo-instance-settings.css`). No configuration change.

**Brand appearance.** The 14 `appearance` colours render as a swatch + hex input + "Use the preset colour". The form
field names, the stored values (`#rrggbb`, lower-cased, null = preset), the save rule (a colour equal to the preset is
not stored), the validation message and the export are unchanged. Hosts with their own section template (`web_ui.templates.section`
or an embed `template`) that do not extend the bundle's: add
`{% if color_picker|default(false) %}{{ include('@NowoInstanceSettingsBundle/settings/_color_assets.html.twig') }}{% endif %}`
(without it the hex inputs keep working; the swatch stays hidden). A template that sets `form_theme ... only` must add
`@NowoInstanceSettingsBundle/Form/color.html.twig`.

**Image fields** now render as one card each with UiKit's image input box (requires `nowo-tech/ui-kit-bundle` **^2.47**):
the stored image, else the default (`default_url`; Brand: the configured default asset) with a badge, a local preview
before saving, Replace / Remove / Undo in the box, the help under it. The form field names (`<field>[file]`,
`<field>[remove]`) and the upload handling are unchanged, and without JavaScript the plain file input and the remove
checkbox still submit as before. Changes to check:

- The separate preview list above the form (`image_previews`, `data-testid="instance-settings-image-previews"`) is no
  longer rendered by the bundle template; the variable is still passed to host templates. `preview: true` is accepted
  and has no effect on the bundle template.
- The row of an image field has no label of its own: the field label and help moved to its file input
  (`<field>_file_help`); the "Upload an image" / "Replace the stored image" labels are no longer shown.
- Hosts with their own section template load UiKit's `image-input` script
  (`script_names: nowo_ui_script_names()|merge(['image-input'])`) and
  `{{ include('@NowoInstanceSettingsBundle/settings/_styles.html.twig') }}` (cards); `img-src` needs `blob:` for the
  local preview.
- `withFormOptions(['image_input' => false])` keeps the pre-1.12 markup on a field.

**Migrating your own String + Regex colour fields** (optional; they keep working as they are):

```php
// Before
SettingField::string('accent', '#1d4ed8', 'brand.accent', constraints: [new Regex('/^#[0-9a-fA-F]{6}$/')]);

// After (1.12)
SettingField::color('accent', '#1d4ed8', 'brand.accent');
// A preset that depends on another field (e.g. a theme choice):
SettingField::color('accent', label: 'brand.accent')->withPresetFrom('theme', ['light' => '#1d4ed8', 'dark' => '#93c5fd']);
```

Stored rows stay valid when they are `#rrggbb`, any case (the type reads them as they are; a new save lower-cases
them). A stored value that is **not** `#rrggbb` (e.g. `red`, `#abc`) is ignored on read: `get()` returns the field
default, as for any row that does not match its type (`isOverridden()` stays true while the row exists). The form shows
that default too, so the next save of the section replaces the row: it is deleted when the default is the preset, else
the submitted colour is stored. Before switching, list such rows (e.g.
`SELECT name, value FROM instance_setting_value WHERE section = '<section>'`; `value` is JSON, mind
`doctrine.table_prefix`) and fix them, or keep the field a String + Regex field. After the switch, a save of the preset colour stores null instead of the colour; if your code needs the
colour, read `get()` (the default) or `SettingField::presetColor()`. Keep your own `Regex` constraint if you want its
message; the type check runs first with `This value is not a #rrggbb colour.` A `SettingsSectionSaveFilterInterface`
that already drops preset colours keeps working.

## To 1.11.1

From **1.11.0**: `composer update nowo-tech/instance-settings-bundle`. No configuration change.

Inside your own transaction, `SettingsChangedEvent` is no longer dispatched from inside your commit but right after
it, at the next safe point (the next settings event, a Doctrine `postFlush`, the end of the request / command /
message). A listener that writes to the database now commits its own work normally. To get the events at once after
your commit, call `Nowo\InstanceSettingsBundle\Event\SettingsPendingEventsInterface::dispatchPending()`.

## To 1.11.0

Target **1.11.0**, from **1.10.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

No configuration change for a standard application.

1. **Your pending changes are no longer flushed by a settings save.** Before 1.11, `save()` (and imports, seeds,
   `SettingValueRepositoryInterface::apply()`) flushed the whole default entity manager. If some code of yours relied
   on a settings save to persist its own entities, flush them yourself. A failed settings write no longer resets your
   entity manager either; it only closes the bundle's private one.
2. **Rows loaded through your own entity manager** (for example `$em->getRepository(SettingValue::class)`) are not
   the instances the bundle writes: refresh them, or read through `SettingRowReaderInterface` / `InstanceSettingsInterface`.
3. **`doctrine.entity_manager`** now maps the entities on that manager only and requires a literal manager name set in
   the host configuration. If you mapped them on that manager by hand, you may remove your mapping. If you set the
   option but kept the tables on the default manager's database, remove the option (or move the tables).
4. **Deprecated:** the entity-typed `SettingValueRepositoryInterface::findAll()`, `findBySection()` and `apply()` as a
   public API. Read with `SettingRowReaderInterface` and write with the new `SettingRowWriterInterface::writeRows()`.
   Custom implementations of the interface keep working in 1.x.

## To 1.10.0

From **1.9.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

## To 1.10.0

Target **1.10.0**, from **1.9.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

1. **Event timing.** `SettingsChangedEvent` is now dispatched after the write is committed. Code that saves without
   its own transaction sees no difference. When you wrap `save()` (or an import, or a seed in a migration) in your own
   transaction on the bundle's connection, the event now arrives after your **outermost commit** instead of right after
   the bundle's flush, and **not at all** after a rollback (also of a savepoint enclosing the save). A listener that
   wrote into that same transaction now runs after it, outside of it: open its own transaction if it needs one. A
   listener that throws during a deferred dispatch no longer fails your commit (which already happened); the error is
   logged. Event name and payload are unchanged. Caches that listen to this event (MailKit's transport, SmsKit's
   resolver, your own) therefore refresh after your commit: a send inside the same transaction still uses the old
   values.
   **MySQL / MariaDB and migrations with DDL:** a DDL statement commits implicitly, so a seed run inside a transactional
   migration that also changes the schema sends no `SettingsChangedEvent` (the transaction DBAL tracks never commits
   cleanly). Run the seed in its own migration, or in a non-transactional one (`isTransactional(): false`), which the
   seeder already recommends.
2. **DBAL middleware everywhere.** The bundle's `doctrine.middleware` (on the connection of `doctrine.entity_manager`
   only) is now registered with every store, not only `files.store: filesystem`. Code that built
   `FileStoreTransactionMiddleware` / `…Driver` / `…Connection` by hand keeps working (the store is now one of
   `TransactionObserverInterface ...$observers`).
3. **Optional: DTO reads.** Inject `SettingRowReaderInterface` instead of `SettingValueRepositoryInterface` when your
   code only reads stored rows; it returns `StoredSettingRow` DTOs. Nothing to change otherwise.

## To 1.9.0

From **1.8.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Target **1.9.0**, from **1.8.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

1. **Nothing changes by default.** `web_ui.hidden_secrets` is `drop` and `web_ui.hidden_secrets_notice` is `false`:
   a secret typed into a hidden provider row is still ignored and no notice is shown.
2. **Opt in** to save such secrets with `web_ui.hidden_secrets: keep_typed`, or per section by implementing
   `SettingsSectionHiddenSecretsInterface`. Review the sections first: with `keep_typed`, a credential typed for an
   inactive provider is written (and announced by `SettingsChangedEvent`) even though that provider stays unselected.
3. **Notice:** `web_ui.hidden_secrets_notice: true` tells users, while hidden secrets are dropped, that they are not
   saved. Override `notice.hidden_secrets_dropped` (domain `NowoInstanceSettingsBundle`) for your own wording.
4. **Code that builds `SettingsSectionEmbedder` by hand** keeps working (two new optional constructor arguments at
   the end, defaults `HiddenSecrets::Drop` and `false`). A `SettingsController` built with the 1.5 signature (no embedder
   service, so it builds its own fallback embedder) ignores the bundle-wide `web_ui.hidden_secrets` and
   `web_ui.hidden_secrets_notice` (it drops, without notice); a section implementing
   `SettingsSectionHiddenSecretsInterface` still gets its own mode there.
5. New translation keys `notice.hidden_secrets_dropped` and `notice.hidden_secret_invalid` in all 7 locales: hosts that check key parity of their
   overrides may need it.

## To 1.8.0

From **1.7.1**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Target **1.8.0**, from **1.7.x**:

```bash
composer update nowo-tech/instance-settings-bundle nowo-tech/ui-kit-bundle
```

1. **UiKit ^2.43.** The bundle now requires `nowo-tech/ui-kit-bundle` ^2.43 (its image crop). Run `assets:install`
   (or your asset build) so `nowo-ui-image-crop.js` is published.
2. **Nothing changes by default.** `files.crop` is `false`: the admin markup is byte-identical to 1.7.1 and no script
   is added.
3. **Opt in** with `files.crop: true` (every image field, brand ones included) or per field with
   `SettingField::withCrop()`; `SettingField::withoutCrop()` keeps a field out. `withCrop()` options a field would refuse
   throw when the section definitions load; under `files.crop` a field the derived crop cannot fit is shown without
   it ([USAGE](USAGE.md#crop-step-18)).
4. **CSP.** With a strict Content-Security-Policy on the admin pages, add `img-src blob:`.
5. **Host templates.** A page template set with `SectionEmbedOptions::withTemplate()` that replaces the
   `nowo_ui_settings_section` block must load the script itself when `image_crop` is true:
   `{{ include(nowo_ui_partial('scripts'), {script_names: ['image-crop']}) }}`.
6. **Building `SettingImageType` by hand** (tests, a form factory without the container): the type now takes `(array|false $cropDefault = false,
   ?TranslatorInterface $translator = null)`; the defaults keep 1.7 behaviour.

## To 1.7.1

From **1.7.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Target **1.7.1**, from **1.7.0**: `composer update nowo-tech/instance-settings-bundle`. Nothing to change in the
configuration; with `files.store: filesystem`:

1. **Env paths.** `files.directory` and `seed.base_dir` may now be `%env(...)%`. The checks that the container applies
   to a literal value (local path; not under the public web root, any spelling) run when the store / seeder is built
   for an env value, so a wrong value fails at kernel warm-up or on the first use instead of `cache:clear`. The
   variable must exist wherever the kernel boots or `cache:warmup` runs (including an image build), or have an
   `env(NAME)` default under `parameters`. Both keys must be **absolute** paths (literal or env): a relative value is
   now refused, as it would resolve against each process's current directory (use `%kernel.project_dir%/…`).
2. **Git.** Add the variants to the host `.gitignore` and commit only the originals:

   ```gitignore
   /config/instance-files/**/.variants/
   ```

   (use your `files.directory`). The store also writes a self-ignoring `.variants/.gitignore` (`*`) from 1.7.1 on.
   If you committed `.variants/` with 1.7.0, `git rm -r --cached config/instance-files/*/.variants`.
3. **Committed images are adopted losslessly.** The next seed on a fresh database strips metadata from a committed
   file once (one git diff for a file that carried EXIF / XMP / ICC / text chunks or appended bytes) and then leaves it
   untouched. A JPEG that 1.7.0 already re-encoded is clean (GD's own `CREATOR` comment is kept) and stays as it is.
   Commit sRGB images: ICC profiles are dropped, as before.

## To 1.7.0

From **1.6.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Target **1.7.0**, from **1.6.0**: nothing to do unless you opt in. Without `seed` and `files.store` the
services, schema and behaviour are unchanged (the database file store is byte for byte the same).

1. **Seed file (IS-3).** Commit a seed file and its images, e.g. `config/instance-seed.yaml` (format and example in
   [CONFIGURATION](CONFIGURATION.md#seed-file-format)), then point the bundle at it:

   ```yaml
   nowo_instance_settings:
       seed:
           file: '%kernel.project_dir%/config/instance-seed.yaml'
           # base_dir: '%kernel.project_dir%'   # every seed path must resolve inside it
   ```

   Run `php bin/console nowo:instance-settings:seed --dry-run`, then without `--dry-run`, in your bootstrap of fresh
   instances (dev, e2e, install) — it is fill-only and idempotent. YAML needs `symfony/yaml` (`.json` needs nothing).
   Secrets are refused in a seed file: keep them in the admin UI or seed them with `seed-from-fallbacks`.
2. **Filesystem image store (optional).** To keep the brand images as committed files instead of database blobs:

   ```yaml
   nowo_instance_settings:
       files:
           store: filesystem
           directory: '%kernel.project_dir%/config/instance-files'
   ```

   Images already stored in `instance_setting_file` are **not** moved. Before switching, export with files
   (`nowo:instance-settings:export --include-files` or the admin page); after switching, import that file, re-upload the
   images, or clear the fields and run the seed. An image of the same hash as the stored reference is written again
   when the new store does not hold it (audit JG-M2). The old blobs stay in `instance_setting_file` (`purge-files` of the
   filesystem store does not remove them). In production mount `files.directory` as a persistent, writable volume
   outside the public web root (the container refuses a directory under `public/` or `brand.public_dir`). Note the
   differences: no orphan retention (a replaced or cleared image is deleted at once), the file on disk is
   authoritative, and a file without a setting row is not served.
3. **Committed images are re-encoded when seeded** with the filesystem store (as uploads are): the committed file at
   the field's stable path may change once in git (EXIF / appended bytes dropped).
4. **`doctrine/dbal` ^4.0** is now a direct requirement. Hosts still on DBAL 3 stay on 1.6 until they upgrade
   DBAL. DoctrineBundle 3 needs DBAL 4, but ORM 3 also runs on DBAL 3.8+, so an ORM 3 host on DBAL 3 must upgrade DBAL
   first (audit JG-I18).
5. **`SettingFileController` built by hand** (not through the container): pass the new fourth argument
   (`InstanceSettingsInterface`); without it every file of the filesystem store answers 404. The database store is
   unaffected.
6. **`ImageUpload::fromPath()`** now refuses `scheme://` and `data:` paths (`upload_failed`). Only code that passed a
   stream wrapper path on purpose is affected.

## To 1.6.0

From **1.5.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Target 1.6.0, from **1.5.0**. Nothing to do: both additions are opt-in and the bundle pages render as in 1.5.

- **Embedding sections in your own page (IS-1).** Replace a forward to `SettingsController::section` (which showed
  every tab and redirected to the bundle route) with `SettingsSectionEmbedder::handle()` in your controller:

  ```php
  public function __invoke(Request $request, SettingsSectionEmbedder $embedder, string $section): Response
  {
      return $embedder->handle($request, $section, SectionEmbedOptions::route('app_appearance', ['brand', 'appearance'])
          ->withLayout('admin/layout.html.twig'));
  }
  ```

  Keep your own firewall / `access_control` on the host route; the embedder adds the bundle checks on top (UI gate +
  section area + CSRF). Your route must accept GET and POST. Pass route names only (a path or URL is refused), call
  the embedder from your route's controller (not a Twig `render(controller())` fragment), and keep `routeParameters`
  to values from your code. A role that should open the embed needs the UI role (`access_roles`) too, not only
  `area_roles.<section>`.
- **Theme cards (IS-2).** Set `nowo_instance_settings.brand.preset_picker: cards` to show the preset fields as cards.
  The stored values and the export do not change. A host that overrides the section template with its own
  `form_theme ... only` must add `@NowoInstanceSettingsBundle/Form/theme_cards.html.twig` itself.
- Code that builds `SettingsController` by hand keeps working: without the new last argument (`SettingsSectionEmbedder`)
  `section()` builds the same embedder from the controller's arguments and its `AbstractController` service locator
  (form factory, Twig, router, request stack). The container wiring passes the service.

## To 1.5.0

From **1.4.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Target **1.5.0**, from **1.4.0**:

```bash
composer update nowo-tech/instance-settings-bundle nowo-tech/ui-kit-bundle
```

1. **UiKit ^2.40.** The constraint moves from `^2.23` to `^2.40` (the brand bridge uses UiKit's logo slots and its
   `provider_is_default` parameter). Nothing else changes for hosts that do not enable the brand bridge: same
   services, routes, forms, schema, and the admin pages render byte for byte as in 1.4.
2. **Opt in to the brand bridge** (spec 220 S3) only if you want the brand and appearance stored as settings:

   ```bash
   composer require nowo-tech/brand-contracts:^1.0
   ```

   ```yaml
   # config/packages/nowo_instance_settings.yaml
   nowo_instance_settings:
       brand:
           enabled: true
           defaults:
               name: Acme                                           # required
               eyebrow: { key: brand.eyebrow_default, domain: messages }   # or a literal string
               logo_light: brand/logo-light.svg                     # committed files under public/ (SVG / ICO allowed)
               mark: brand/mark.svg
               favicon: brand/favicon.svg
           extra_images:
               mascot: { label: brand.mascot, default: brand/mascot.png }
   ```

   - `brand.enabled: true` implies `files.enabled: true`: if you did not have image fields yet, add the
     `instance_setting_file` migration of [To 1.4.0](#to-140), `ext-gd` with PNG, JPEG and WebP, and the
     `PUBLIC_ACCESS` `access_control` line for `files.path_prefix` (brand images are public).
   - Set `brand.enabled` in the host configuration (or a bundle that prepends before InstanceSettings). A value
     prepended after InstanceSettings now fails at compile time with a clear message (audit IO-I10).
   - Two sections appear in the admin UI, `brand` and `appearance` (area roles `security.area_roles.brand` /
     `.appearance`, default the UI roles). A host section already named `brand` or `appearance` conflicts: rename it
     before enabling the bridge.
   - **UiKit's appearance provider switches to the bridge** when `nowo_ui_kit.appearance.enabled: true`, you set no
     `nowo_ui_kit.appearance.provider` (and no services alias of `nowo_ui_kit.appearance.provider`) and you kept
     `nowo_ui_kit.appearance.brand: auto` (the default). With `brand: off` UiKit keeps its config look while Pwa, mail,
     legal and SEO still read the stored brand (audit IU-L1). A host **decorator** of
     `nowo_ui_kit.appearance.config_provider` no longer applies once the bridge takes over (audit IU-I1): set
     `nowo_ui_kit.appearance.provider` to your own provider, or `brand: off`, to keep it. With an empty store
     the output equals UiKit's config provider when `brand.defaults.name` / `eyebrow` match
     `nowo_ui_kit.appearance.defaults.brand_name` / `brand_eyebrow`. A host provider always wins.
   - `BrandProviderInterface` and `BrandAssetReaderInterface` are aliased to the bridge. A host service or alias of
     `BrandProviderInterface` next to `brand.enabled: true` fails the build (two brands, audit IU-I2): keep one source.
   - Default asset URLs are `/<path>?v=<12 hex of the SHA-256>`, so a changed committed file gets a new URL (audit
     IU-I3). They are root-relative and ignore a request base path: an app served under a sub-path needs the files
     reachable at `/<path>` or uploads instead. Pwa, MailKit, LegalKit and SeoKit read them in their brand-aware releases.
   - Migrating from a host appearance entity: implement `Brand\BrandFallbackProviderInterface` (one read of the old
     row, only values that differ from the defaults), set `brand.fallback_provider` to its service id, run
     `bin/console nowo:instance-settings:seed-from-fallbacks --section=brand --section=appearance --dry-run`, then
     without `--dry-run`. Images are not seeded: keep them as config default assets or upload them.
3. **Custom `SettingsSectionInterface` implementations** are unaffected. `SettingField` gains an optional trailing
   constructor argument `$group`; code that builds fields with named arguments or the named constructors needs no
   change.

## To 1.4.0

From **1.3.1**:

```bash
composer update nowo-tech/instance-settings-bundle
```

Image setting fields (spec 220 S2):

1. **No schema change unless you enable image fields.** Image fields are opt-in (`files.enabled`, default `false`):
   by default the bundle maps no new entity, `doctrine:migrations:diff` and `doctrine:schema:validate` see the 1.3
   schema, and no file route, file store or purge command is registered.
2. **Only hosts that set `nowo_instance_settings.files.enabled: true`** (to declare image fields) get the new table
   `instance_setting_file`. Generate a migration (`doctrine:migrations:diff`) or apply the DDL below (shown without
   `doctrine.table_prefix`; with a prefix, prefix the table and both index names):

   ```yaml
   nowo_instance_settings:
       files:
           enabled: true
   ```

   MySQL / MariaDB:

   ```sql
   CREATE TABLE instance_setting_file (id INT AUTO_INCREMENT NOT NULL, orphaned_at DATETIME DEFAULT NULL, section VARCHAR(64) NOT NULL, name VARCHAR(64) NOT NULL, sha256 CHAR(64) NOT NULL, variant INT NOT NULL, mime_type VARCHAR(32) NOT NULL, width INT NOT NULL, height INT NOT NULL, byte_size INT NOT NULL, content LONGBLOB NOT NULL, created_at DATETIME NOT NULL, INDEX idx_instance_setting_file_orphaned (orphaned_at), UNIQUE INDEX uniq_instance_setting_file_ref (section, name, sha256, variant), PRIMARY KEY (id));
   ```

   PostgreSQL:

   ```sql
   CREATE TABLE instance_setting_file (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, orphaned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, section VARCHAR(64) NOT NULL, name VARCHAR(64) NOT NULL, sha256 CHAR(64) NOT NULL, variant INT NOT NULL, mime_type VARCHAR(32) NOT NULL, width INT NOT NULL, height INT NOT NULL, byte_size INT NOT NULL, content BYTEA NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id));
   CREATE INDEX idx_instance_setting_file_orphaned ON instance_setting_file (orphaned_at);
   CREATE UNIQUE INDEX uniq_instance_setting_file_ref ON instance_setting_file (section, name, sha256, variant);
   ```

3. **Nothing else changes for hosts without image fields**: no new table, no new route, the same forms, exports stay
   envelope version 1.
4. **Hosts that add an image field** need `files.enabled: true` (a section declaring an image field without it fails
   when the definitions load), `ext-gd` with PNG, JPEG and WebP (the container refuses to compile without it once the
   flag is on), a `PUBLIC_ACCESS` `access_control` line for `files.path_prefix` (default `/_settings-file`) when their
   firewall covers it (and a firewall over that prefix for private image fields), and a scheduled
   `nowo:instance-settings:purge-files`. Behind a CDN, ignore the query string in the cache key for that prefix and
   purge the CDN when you withdraw an image. Raise `portability.max_upload_bytes` (up to 16 MiB) if exports include
   the images. A 1.3 instance cannot import a version 2 export.
5. **Custom `InstanceSettingsInterface` implementations** (rare: decorators, test doubles) must add
   `getFile(string $key): ?StoredSettingFile`. Listeners constructing `SettingsChangedEvent` themselves are unaffected
   (`fileChanges` is optional).

## To 1.3.1

From **1.3.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

From **1.3.0**: no configuration or schema change. Saves that emptied a provider whose default shows a required field
(and left that field empty) are now refused, as they would leave the default provider without its required value.

## To 1.3.0

From **1.2.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

From **1.2.x**: no configuration or schema change, no BC break. Requires `nowo-tech/ui-kit-bundle` ^2.23
(`composer update nowo-tech/instance-settings-bundle nowo-tech/ui-kit-bundle -W`).

- Opt-in provider-dependent fields: `->withVisibleWhen('provider', ['smtp'])` on a `SettingField`
  ([USAGE](USAGE.md#provider-dependent-fields-visible_when)).
- Hosts that render a section with such fields register the UiKit `visible-when` Stimulus peer
  (`vendor/nowo-tech/ui-kit-bundle/src/Resources/assets/stimulus-peers/visible_when_controller.ts`, identifier
  `visible-when`). Without it every row stays visible and saving still follows the server rules.
- A section definition with an invalid `visible_when` (unknown / secret / dependent controlling field, value that is
  not a choice) now fails with a `LogicException` when the sections load.

## To 1.2.0

From **1.1.1**:

```bash
composer update nowo-tech/instance-settings-bundle
```

### To 1.2.0

From **1.1.x**: no configuration or schema change, no BC break.

```bash
composer update nowo-tech/instance-settings-bundle
```

New, all optional:

- `SectionFallbackProviderInterface` + `FallbackSeeder` + `nowo:instance-settings:seed-from-fallbacks`: copy the
  `%env()%` / YAML fallbacks of the kits (SmsKit / MailKit 1.2+) into the store once. See
  [Seeding from fallbacks](USAGE.md#seeding-from-fallbacks-db-only-mode).
- `SettingsSectionNoticesInterface`: warnings on the section page. A host that overrides
  `web_ui.templates.section` gets a new optional `notices` variable (list of UiKit labels); render it or ignore it.
- `SettingsChangedEvent::ORIGIN_SEED` (`seed`): listeners that switch on the origin should accept it.

Moving a host to DB-only mode, **in this order**:

1. Upgrade the bundle and the kits while the `%env()%` fallbacks are still wired in their configuration.
2. Run `php bin/console nowo:instance-settings:seed-from-fallbacks --dry-run`, then without `--dry-run` (or call
   `FallbackSeeder::seed()` from an upgrade step) **once**.
3. Check the settings pages (no warnings), then remove the env vars / YAML values right away. Removing them first would
   seed nothing; keeping them and seeding again would restore a field an admin cleared in the meantime.

From a Doctrine migration, use a **non-transactional** migration (`isTransactional(): false` or
`doctrine_migrations.transactional: false`): on MySQL, DDL commits implicitly and a transactional migration leaves DBAL
with a phantom transaction (`SAVEPOINT DOCTRINE_n does not exist`). The seeder detects it and throws a clear
`LogicException`. See [Calling the seeder from a migration](USAGE.md#calling-the-seeder-from-a-migration).

## To 1.1.1

From **1.1.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

The bundle now requires `nowo-tech/ui-kit-bundle` ^2.21. No configuration or code change:

```bash
composer update nowo-tech/instance-settings-bundle nowo-tech/ui-kit-bundle --with-dependencies
```

## To 1.1.0

From **1.0.0**:

```bash
composer update nowo-tech/instance-settings-bundle
```

## To 1.0.0

Initial release: there is nothing to upgrade from.

### Requirements

- PHP `>=8.2 <8.6`, Symfony 7.4 / 8.0 / 8.1, Doctrine ORM 3.3+.
- `nowo-tech/doctrine-encrypt-bundle` ^2.10 registered **and configured** (default profile): the container refuses to
  compile without it.

### Enable and configure

1. `composer require nowo-tech/instance-settings-bundle` (Flex registers the bundle and copies the recipe).
2. Import the routes (opt-in):

   ```yaml
   # config/routes/nowo_instance_settings.yaml
   nowo_instance_settings:
       resource: .
       type: nowo_instance_settings
   ```

3. Protect `web_ui.path_prefix` (`/admin/settings`) with `security.access_control`.
4. Generate and run a migration for `instance_setting_value` (`doctrine:migrations:diff`).
5. Contribute sections ([USAGE.md](USAGE.md#contributing-a-settings-section)).

### Migrating from an in-app settings entity

Applications with a singleton "instance settings" entity (one wide row, one trait per area) move area by area:

1. Write one section per area (`mailer`, `sms`, `mercure`, …) with the same defaults; mark encrypted columns
   `SettingField::secret()` and security-sensitive toggles `->notPortable()`.
2. Copy the stored values once, in a migration or a one-off command, through the API so secrets are re-encrypted:

   ```php
   $settings->save('mailer', ['from' => $legacy->getMailerFrom(), 'dsn' => $legacy->getMailerDsn()]);
   ```

3. Replace `$repository->getOrCreate()->getX()` reads with `$settings->getString('area.x')` / `getSecret()`.
4. Replace the area controllers / forms / templates with the bundle UI; keep domain-specific actions (test e-mail…) as
   section actions.
5. Replace the export / import controller and envelope with `ConfigPortability` (+ `PortabilityProviderInterface`
   services for data stored elsewhere); set `portability.schema` to your previous schema name only if the payload shape
   is the same (it usually is not: re-export after migrating).
6. Drop the old columns / entity in a later migration.

### Template contract

Initial contract: pages `settings/section.html.twig` and `settings/portability.html.twig`, both extending
`@NowoUiKit/page/settings.html.twig`; view models from `SettingsPageFactory` (see [TEMPLATES.md](TEMPLATES.md)).
Renaming a page, a `web_ui.templates` id or a view-model field will be a breaking change.

### Breaking changes

None (first release).
