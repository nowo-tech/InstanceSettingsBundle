# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.16.1] - 2026-11-14](#1161---2026-11-14)
- [[1.16.0] - 2026-11-14](#1160---2026-11-14)
- [[1.15.1] - 2026-11-14](#1151---2026-11-14)
- [[1.15.0] - 2026-11-13](#1150---2026-11-13)
- [[1.14.1] - 2026-11-13](#1141---2026-11-13)
- [[1.14.0] - 2026-11-11](#1140---2026-11-11)
- [[1.13.1] - 2026-11-11](#1131---2026-11-11)
- [[1.13.0] - 2026-11-11](#1130---2026-11-11)
- [[1.12.0] - 2026-11-11](#1120---2026-11-11)
- [[1.11.1] - 2026-11-11](#1111---2026-11-11)
- [[1.11.0] - 2026-11-11](#1110---2026-11-11)
- [[1.10.0] - 2026-11-10](#1100---2026-11-10)
- [[1.9.0] - 2026-11-10](#190---2026-11-10)
- [[1.8.0] - 2026-11-09](#180---2026-11-09)
- [[1.7.1] - 2026-11-09](#171---2026-11-09)
- [[1.7.0] - 2026-11-09](#170---2026-11-09)
- [[1.6.0] - 2026-11-09](#160---2026-11-09)
- [[1.5.0] - 2026-11-09](#150---2026-11-09)
- [[1.4.0] - 2026-11-08](#140---2026-11-08)
- [[1.3.1] - 2026-11-02](#131---2026-11-02)
- [[1.3.0] - 2026-11-02](#130---2026-11-02)
- [[1.2.0] - 2026-11-02](#120---2026-11-02)
- [[1.1.1] - 2026-11-01](#111---2026-11-01)
- [[1.1.0] - 2026-10-31](#110---2026-10-31)
- [[1.0.0] - 2026-10-31](#100---2026-10-31)

## [Unreleased]

Target 1.17.0 (Symfony Beacon request, owner decision D-20261114-09).

### Added

- **Expiring secrets (opt-in).** `SettingField::secret(..., expires: true)` gives a secret field a companion expiry
  date (date only), stored next to it in the new opt-in table `instance_setting_secret_expiry` and mapped only with
  `nowo_instance_settings.expiring_secrets.enabled: true` (off by default: no schema change, and fields without
  `expires` behave exactly as in 1.16).
  - **Save rule.** Saving a **new secret** clears its date, or replaces it with the date given in the same save
    (`'<field>:expires_on' => '2027-01-31'` in `InstanceSettingsInterface::save()`; the admin form passes the date only
    when the user changed it). Removing the secret removes the date. A date alone updates it while a secret is stored
    (refused with a violation otherwise). A date-only change dispatches no `SettingsChangedEvent`; `save()` returns
    `<field>:expires_on` among the changed keys.
  - **Admin UI.** A date row (`<field>:expires_on`, `DateType` single text, UTC on both sides) right after the secret
    row, prefilled with the stored date, in the secret's subtab with `web_ui.section_layout: tabs`, hidden with its
    secret for provider-dependent fields. Within `expiring_secrets.warning_days` (default 30) of the date, or once
    expired, a warning above the section form and as the help text of the date row; every text is translated and
    escaped (seven locales, keys `secret_expiry.*`).
  - **Read port** `Expiry\ExpiringSecretSourceInterface::expiringWithin(int $days, DateTimeImmutable $today)` →
    `list<Expiry\ExpiringSecret>` (section, field, label key, translation domain, date, days left; never the secret).
  - **Threshold event** `Event\SecretExpiryThresholdReachedEvent` (scalars only) dispatched by
    `nowo:instance-settings:check-expiring-secrets` once per threshold per expiry date (`expiring_secrets.thresholds`,
    default `[30, 7, 1, 0]`). The last announced threshold is stored next to the date and claimed with one conditional
    UPDATE, so restarts, workers and concurrent runs never announce it twice; a new date resets it; a failing listener
    releases the claim (retried on the next run, exit code 1). Several thresholds crossed at once announce the smallest.
  - **Store port** `Expiry\SecretExpiryStoreInterface` (`SecretExpiryRecord` VO) with the DBAL implementation
    `DoctrineSecretExpiryStore` on the bundle's private entity manager (standard and DDD hosts, `doctrine.table_prefix`).
  - **Clock.** "Today" comes from `ClockInterface` in `expiring_secrets.timezone` (default the PHP default zone) on
    every call; nothing is memoized (FrankenPHP worker mode with kernel reset off).
  - **Export / import** carry the dates in clear under `secret_expiry` (envelope version 3, only when a date is
    exported; other exports keep version 1 / 2). An imported date is applied with its secret (the sealed one of the file,
    else the stored one) and skipped for a field left without a secret. **Fallback seeding**:
    `SettingFallback::secret($field, $value, '2027-01-31')` seeds the date with the secret.
- **Configuration** `expiring_secrets: {enabled, thresholds, warning, warning_days, timezone}`.

### Changed

- `PortabilityEnvelope::assertValid()` accepts versions 1 to 3 (`VERSION_WITH_SECRET_EXPIRY`); `KEYS` lists
  `secret_expiry`. `PortabilityEnvelope::VERSION` stays 2.
- Optional trailing constructor arguments: `InstanceSettings` (`expiryStore`), `ConfigPortability` (`expiryStore`),
  `FallbackSeeder` (`expiryStore`), `SettingsSectionEmbedder` (`expiringSecrets`), `SettingsSectionRegistry`
  (`expiryEnabled`), `SettingField` (`expires`), `SettingFallback` (`expiresOn`). Code building them by hand keeps
  working.

### Documentation

- USAGE ([Expiring secrets](USAGE.md#expiring-secrets-117)) with the Beacon-style reminder listener, CONFIGURATION
  ([`expiring_secrets`](CONFIGURATION.md#expiring-secrets-117)), UPGRADING (DDL for MySQL, MariaDB, PostgreSQL and
  SQLite).

## [1.16.1] - 2026-11-14

Target 1.16.1.

### Fixed

- **A revealed secret no longer survives in Turbo Drive's page cache or the back / forward cache** (audit PK-L1, Low,
  pre-existing since 1.15; no GHSA: same tab, same authorised user). On hosts using Turbo Drive, a Turbo link visit
  fires neither `submit` nor `visibilitychange`, so Turbo's snapshot kept the `<output>` text and Back showed the value
  again without the 60-second auto-hide. `nowo-instance-settings-reveal.js` now clears every revealed row — Reveal
  button and eye alike, calls still in flight included — on `turbo:before-cache` and on `pagehide` (audit PK-I2). The
  clipboard is kept, as for a hidden page (audit N1). Turbo is not a dependency: the script only listens for its
  event.
- **`reveal_toggle_only`: an error message no longer stays on screen** (audit PK-I7). With no Hide button, a refused
  reveal's message now goes away on the next eye click or by itself after 10 seconds. Unchanged when the Hide button
  is there.
- **Holding Enter on the eye no longer fires a reveal call per key repeat** (audit PK-I8). Auto-repeated `keydown`
  events (`event.repeat`) are ignored — still kept from PasswordToggle, so they never unmask the input — and a click
  that would reveal while the row's previous call is still in flight is ignored, for the eye and the Reveal button.
  A click that hides still hides at once and drops the late answer.

### Documentation

- CONFIGURATION ([Revealing from the password eye](CONFIGURATION.md#revealing-from-the-password-eye-116)): the
  `aria-live` output makes a screen reader read the revealed secret aloud (audit PK-I1); the new hide triggers, the
  `reveal_toggle_only` error timeout and the one-call-at-a-time rule.
- `AppearanceSection::getIcon()` docblock: the override key is `web_ui.section_icons.appearance` (audit PK-I3).
- The reveal-on-toggle Playwright spec covers late answers (eye clicked again, page hidden), `turbo:before-cache`,
  `pagehide`, the auto-hide delays, auto-repeated Enter and clicks while a call is in flight (audit PK-I4).

## [1.16.0] - 2026-11-14

Target 1.16.0.

### Added

- **Tab icons for the bundle's sections.** `BrandSection::getIcon()` is `tabler:palette` and
  `AppearanceSection::getIcon()` `tabler:layout-dashboard` (prefixed UiKit icon names, shown by the `tabler-icons` and
  `ux_icon` sets).
- **`web_ui.section_icons: {<section>: <icon>}`.** Overrides or fills the tab icon of any section, other bundles'
  included, on the bundle page and in embeds. Icons are validated when the container is built (canonical UiKit kind
  or `prefix:name`); names must be lower snake case, and a name that matches no registered section is ignored with a
  debug log line (sections are only known at runtime).
- **`web_ui.navigation_hidden: [<section>, ...]`.** Leaves sections out of the bundle page's tab bar and of the "first
  section" redirect, which now picks the first visible section the user may open (all hidden: the first one, as
  before). Presentation only: a hidden section stays registered, guarded by the same checks, in exports, embeddable
  through `SettingsSectionEmbedder` (whose tabs follow the embed allowlist), and its own URL keeps working. Requested
  by Symfony Beacon (`navigation_hidden: [brand, appearance]`).
- **`web_ui.reveal_on_toggle`** (default `false`) and **`web_ui.reveal_toggle_only`** (default `false`). On a
  revealable secret row, the PasswordToggle eye reveals the stored secret through the existing reveal call (same POST
  endpoint, CSRF token, rate limiter, access checks and audit) and hides it on the second click. The value is shown
  in the row's read-only `<output>` (no name, never form data), never in the input; it is dropped on hide, after the
  auto-hide delay, when the page is hidden (clipboard kept, audit N1), on submit, and a late answer after a hide is
  discarded. A refused reveal shows the Reveal button's message and the eye stays hidden; the eye carries
  `aria-pressed` and "Reveal stored value" / "Hide stored value" (new keys `reveal.toggle_show`, `reveal.toggle_hide`).
  `reveal_toggle_only` drops the Reveal / Copy / Hide buttons on rows that have the eye. Requested by Symfony Beacon
  from an owner UX report.
- `Ui\SectionNavigation` (icons, hidden sections, first visible section); `SettingsPageFactory::navigation()`.

## [1.15.1] - 2026-11-14

Target 1.15.1.

### Security

- **The reveal refusal line names the actor, can no longer be chosen by the caller and is capped per caller** (audit of
  2026-11-13, N2 / N3; Low, no GHSA: only 1.15.0 has the reveal). Both writers of
  `Instance settings: secret reveal refused` — the UI gate (`ui_gate`) and `SecretRevealer` (`forbidden`,
  `not_revealable`, `rate_limited`, `not_text`) — now go through one internal `Secret\RevealRefusalLog`:
  - `actor` is the resolved user identifier on the `ui_gate` line too, which hard-coded `null`, so the per-actor alert
    the line exists for could not group the refusals of an account that cannot open the UI (N2). It is the value the
    reveal line, the other refusal reasons and `SecretRevealedEvent` already carry since 1.15; `null` still means a
    caller the host cannot name.
  - `key` is `<section>.<field>` only when the registry declares exactly that section and field (byte for byte);
    any other name is logged as the constant `unknown`, never echoed (N3). A declared field that is not revealable
    keeps its name.
  - The line is throttled per caller (user identifier, else client IP) by `security.reveal_rate_limiter`, in a bucket
    of its own (`refusal_log`, so refusals never spend the caller's reveals): a `warning` while the caller has budget,
    `debug` past it (N3). Without a limiter (then nothing in the bundle is revealable) a caller the host cannot name
    gets `debug` lines and a named caller keeps `warning`. Every refusal is still refused: a failure while writing the
    line cannot turn the UI gate's 403, or the revealer's 403 / 404 / 409 / 429, into anything else: the line is
    written in a `try` / `finally` and never throws; a broken limiter storage or logger leaves a constant `error`
    line "Instance settings: secret reveal refusal could not be logged" instead (audit PJ-L1).
  - The `refusal_log` bucket key cannot collide with a reveal key whatever the identifier (an identifier containing a
    NUL byte could reach another user's bucket); the reveal key keeps its 1.15.0 bytes (audit PJ-I1).
  - SECURITY / CONFIGURATION: the warning cap is per caller (rotating IPs get a budget each), so pair the per-actor
    alert with an aggregate one; each refused reveal writes a limiter entry; `actor` is personal data, logged as
    structured context since 1.15.0 (audit PJ-L2, PJ-I3, PJ-I4).

### Fixed

- `InstanceSettingsAccessListener` takes the refusal log as a new optional fourth constructor argument (wired by the
  extension). A listener built by hand with the 1.15.0 arguments still logs, as the key `unknown` and, for an unnamed
  caller, at `debug`.

### Documentation

- **UPGRADING "To 1.15.0" step 3** now says that a revealable field without `reveal_rate_limiter` takes down **every**
  consumer of the section definitions (admin pages, `InstanceSettings` reads, the commands, embeds), not just the
  reveal (audit N6).

- **Seed files under a volume-mounted store directory.** New "Containers and volumes" (filesystem store) and "Missing
  seed files" (seed command) notes in `CONFIGURATION.md`: a Docker named volume on `files.directory` is filled from the
  image only when it is created, so seed images committed later never reach it and `nowo:instance-settings:seed` is
  refused (`path_not_found`, exit 1, nothing written). Options: sync before seeding, keep seed sources outside the
  store directory, bind mount; recommended deploy order.

## [1.15.0] - 2026-11-13

### Added

- **Reveal a stored secret (opt-in per field).** `SettingField::secret(..., revealable: true)` adds an explicit
  **Reveal** action to that secret's row of the admin form, for a user holding `security.reveal_roles`
  (`ROLE_DEVELOPER` by default) on top of the section's own area. Default `false`: a secret stays write-only ("leave
  blank to keep") unless the host opts in, field by field — for an OAuth client secret, not for an encryption or
  signing key (owner decision of 2026-11-13, from SYMFONY-BEACON Development → Settings).
  - New endpoint `nowo_instance_settings_secret_reveal`
    (`<web_ui.path_prefix>/sections/{section}/secrets/{field}/reveal`, POST + this section's CSRF token), part of the
    admin UI routes. It answers the single named field as `{"key": "<section>.<field>", "value": "…"}` with
    `Cache-Control: private, no-store`, `nosniff`, `Referrer-Policy: no-referrer` and `X-Robots-Tag: noindex`; a
    revealable field with nothing stored answers 404 `{"error": "not_stored"}`. The secret is never in a URL and never
    rendered into a page: the page carries the button, the endpoint URL and the token only.
  - Every check is server-side, on every call: the UI gate, the section area, `reveal_roles`, the field's `revealable`
    flag and the rate limiter. The section and field names from the client are matched byte for byte against the
    declared ones before any lookup (REQ-SEC-006), so a look-alike name cannot reach another field; a field without
    the opt-in and a user without the roles are refused by the endpoint, not only hidden in the UI.
  - Every reveal writes an audit entry naming the field, the actor and the time, never the value (REQ-OBS-001): the
    PSR-14 `Event\SecretRevealedEvent` and an `info` log line; a refusal is a `warning` with its reason
    (`forbidden`, `not_revealable`, `rate_limited`).
  - New configuration `security.reveal_roles` (default `[ROLE_DEVELOPER]`; `[]` refuses every reveal, and
    `security.area_roles._reveal` is refused so there is one place to look) and `security.reveal_rate_limiter`
    (service id of a Symfony `RateLimiterFactory`, one token per reveal keyed by the hashed actor). A custom
    `security.access_checker` decides the new area `InstanceSettingsAccessCheckerInterface::AREA_REVEAL`.
  - **The rate limiter is required with any revealable field** (owner decision of 2026-11-13): a field declaring
    `revealable: true` without `security.reveal_rate_limiter` makes the section definitions fail to load (router
    warm-up, so `cache:warmup` fails in the build, else the first request) with a message naming the field, the option
    and the two ways out (drop the opt-in, or switch reveals off with `security.reveal_roles: []`, which then needs no
    limiter). `symfony/rate-limiter` stays a suggested dependency: a host with no revealable field is untouched — no
    error, no dependency, no behaviour change. Reveal rights are per field, never per section.
  - UI: the revealable row carries `data-nowo-instance-settings-reveal="<field>"` and the page ships
    `nowo-instance-settings-reveal.js` (deferred, CSP nonce, no inline handler) with Reveal / Copy / Hide buttons
    (UiKit classes, 7 locales). The value is dropped on Hide, after a minute and when the tab is hidden. Without
    JavaScript the row stays write-only, as before.
  - Security audit of 2026-11-13 (F1–F6, I7): the reveal answer must be excluded from every server-side response-body
    log — our own HttpLogBundle captures JSON bodies by default and does not redact `value` — which CONFIGURATION,
    UPGRADING and SECURITY now spell out with the configuration (F1); a stored secret that is not UTF-8 text answers a
    clean 409 `{"error": "not_text"}` with the same headers instead of a 500, and is audited as a refusal, never as a
    reveal (F2); a revealable row is bound to the Reveal root of its own section, so a page embedding two sections that
    declare the same field name cannot show one section's secret under the other's label (F3); the documentation names
    the operating system clipboard instead of claiming the value is never stored, and Hide asks for it to be emptied
    (F4); the rate limiter key is salted with `%kernel.secret%` and a caller the host cannot name is keyed by its
    client IP instead of sharing one bucket (F5); a reveal denied by the UI gate — before the endpoint runs — now
    leaves the same reveal refusal line, with the reason `ui_gate`, so an alert on reveal refusals cannot miss it (F6);
    the section, field and group name patterns carry the `D` modifier, so a trailing newline is refused by the pattern
    itself as well as by the route (I7). SECURITY.md also states what the feature changes for a host-side XSS on the
    admin layout (before 1.15 such a script could not obtain any stored secret), the detection that the
    one-field-per-call shape makes possible, and that step-up re-authentication is a 1.16+ feature.
  - The audit's probes are kept as a permanent `Probe` test suite (`tests/Probe`): the kill switch, host checkers that
    grant only part of the three checks, an inconsistent checker, `allow_unauthenticated`, the same field name in two
    sections, a 23-variant look-alike path battery and the non-text secret.
  - Stateless (REQ-RUNTIME-002): nothing about a reveal — value, permission or limiter — is kept on a service between
    requests; the limiter is built per call from its factory.
  - Hiding the page (switching tab or window) still hides every revealed value, but no longer clears the clipboard:
    the admin is usually switching away to paste. The clipboard is cleared by **Hide** and by the 60 s auto-hide only
    (audit N1).

## [1.14.1] - 2026-11-13

### Fixed

- DoctrineBundle 3.4: bundle entities resolved to the default manager when a dedicated manager is configured.
  DoctrineBundle 3.4 `auto_discover_entities` (on by default for a manager without `mappings` and `auto_mapping`, with
  Symfony 7.3+ and doctrine/orm 3.6+) gives that manager's metadata driver chain a default attribute driver, which
  claims every `#[ORM\Entity]` class. With `doctrine.entity_manager` set, `doctrine`'s `getManagerForClass()` then
  answered the host's default manager for `SettingValue` / `SettingFile`, and host-facing code (`getRepository()`,
  EntityType, UniqueEntity, MapEntity) used its database. `EntityManagerMappingPass` now also registers a driver that
  maps nothing for both bundle entity namespaces (`Entity`, `FileEntity`), first in the chain of every other manager
  (`Doctrine\ExcludedEntityNamespaceDriver`, only the public `addDriver()` of the
  `doctrine.orm.<manager>_metadata_driver` chain), so the entities resolve to the configured manager on DoctrineBundle
  2.x, 3.3 and 3.4+. Nothing changes without the option. Affected in practice: 1.11.0 to 1.14.0 (every release with
  `doctrine.entity_manager`; all allow `doctrine/doctrine-bundle` `^3.0`) with DoctrineBundle 3.4.0 or later.

### Changed

- Demo uses the UiKit base style (REQ-DEMO-014)

## [1.14.0] - 2026-11-11

Target: 1.14.0 (minor). Opt-in subtabs for long settings sections (Beacon brand page, `/developer/appearance/brand`).

### Added

- **Field groups as subtabs** (`web_ui.section_layout: tabs`, default `stacked`): a section page shows one UiKit subtab
  per field group. Groups come from the new optional interface `Section\SettingsSectionGroupsInterface::getGroups()`
  (`Section\SettingsGroup`: a code, an optional label key / domain, the field names it claims, the 1.5 form groups it
  claims, `rest` for the fields no other group claims), else from the fields' 1.5 form groups
  (`SettingField::withGroup()`, labels `<section>.group.<code>`). A separate interface, so existing sections keep
  compiling; `Section\SectionGroups::resolve()` assigns every field to exactly one group (fields claimed by none go
  to the `rest` group, else an automatic "Other" group), drops empty groups and renders stacked below two groups. A
  duplicate code or a group listing an unknown field is a `LogicException`.
  - **One form:** Save saves every group. Inactive panels are only `hidden` (never disabled), so their fields, file
    inputs included, are submitted; write-only secrets left blank keep their stored value, encrypted secrets and
    "blank = keep" / "clear" are unchanged.
  - **URL:** the open tab is `?settings_group=<code>` (read on GET; unknown or non-string values ignored), kept by the
    redirect after a save (bundle route and embeds). The parameter is namespaced so a host page keeps its own
    `?group=` (audit MP-L1): `web_ui.section_group_parameter` and `SectionEmbedOptions::withGroupParameter()` rename
    it, the tabs root prints it as `data-nowo-instance-settings-group-parameter` for the script, and the redirect
    replaces it in a host route URL that already carries it instead of appending a duplicate. The new
    `bundles/nowoinstancesettings/js/nowo-instance-settings-tabs.js` (plain, deferred, CSP nonce, idempotent) updates
    the URL with `history.replaceState` on every tab change, so a reload or the form post come back to the same tab.
  - **Accessible:** UiKit's in-page tabs runtime (`nowo-ui-tabs.js`, in UiKit's default script set): role `tablist` /
    `tab` / `tabpanel`, `aria-controls`, `aria-selected`, roving tabindex, ArrowLeft / ArrowRight (mirrored in RTL),
    Home / End, a browser-invalid control in a hidden panel opens it. Without JavaScript every panel is visible as a
    stacked `<fieldset><legend>` and the tab list stays hidden (progressive upgrade).
  - **Errors:** a tab whose fields have validation errors carries `data-invalid` and a badge with the count (plus
    screen-reader text, `tabs.errors`); after a failed save the first tab with an error opens.
  - **Embeds:** `SettingsSectionEmbedder` renders the same subtabs; `SectionEmbedOptions::withSectionLayout('tabs' |
    'stacked' | null)` overrides the config per embed.
  - Markup: `settings/_section_form_tabs.html.twig` (the form of UiKit's `section_form` partial with the rows of each
    group in a panel; UiKit's in-page tab classes and theme slots `locale_tabs_nav`, `tab`, `tab_active`,
    `tab_panel`). UiKit 2.48.1 has no generic in-page tabs partial (only `locale_tabs`, bound to languages: `lang`,
    `dir`, language names), so the bundle prints the markup contract of UiKit's tabs runtime itself. The section page
    gets a `section_groups` variable (`Ui\SectionGroupTabs` in the tabs layout, null otherwise). It is reserved: a host
    `SectionEmbedOptions::withContext()` value of that name never wins (audit MP-I3).
  - **Validation (audit MP-I2):** declared groups are checked when the registry loads the section definitions, in every
    layout: duplicate codes, unknown field names and more than one `rest` group throw a `LogicException`.
  - **Overrides (audit MP-I4):** the tabs layout renders `settings/_section_form_tabs.html.twig` instead of UiKit's
    `section_form` partial; override that template (not the partial) to change it. Without UiKit's `nowo-ui-tabs.js` the
    page falls back to the stacked fieldsets.
- **Default groups of the bundle's sections:** `brand` — Identity (name, short name, eyebrow, wordmark), Logos (light,
  dark, mark, favicon, app icon), Extra images (`brand.extra_images`), Advanced (any other field; not shown while
  empty); `appearance` — Theme (presets), Light colours, Dark colours, Shape (corners, borders, footer). Group labels
  in the 7 catalogues (`brand.group.*`, `appearance.group.*`, `group.other`, `tabs.label`, `tabs.errors`).

### Fixed

- **Audit MH-I-L1 (Low, since 1.7.0): a connection closed mid-transaction stranded later file changes.** The
  filesystem store keyed its open transactions by `spl_object_id()` of the driver connection and forgot them only at
  their end or on `kernel.reset`. `Connection::close()` inside a transaction reports no end, so in a worker without the
  services resetter every later image change of the process was staged into that dead transaction and never written.
  The transactions are now a `WeakMap` keyed by the driver connection, as in `AfterCommitEventDispatcher` (audit
  KI-I-L1): a dropped connection takes its staged changes with it (their temporary files are removed by
  `purge-files`; schedule it, e.g. daily, on hosts whose workers may drop connections mid-transaction, audit MP-I1).
  The boundary listener still does not reset the store: a nested command would drop the outer
  transaction's files.
- Tests: a connection closed mid-transaction (`FilesystemSettingFileStoreTest`); in `WorkerBoundaryResetTest` an
  encrypted secret rotated by another process and read decrypted on the next message (MH-I-I1), and a nested console
  command inside an outer command's transaction that keeps the outer image change (MH-I-I2).

### Unchanged

- The default `stacked` layout renders byte-identical markup (golden tests); the 1.5 row attribute
  `data-nowo-instance-settings-group` keeps its values (`identity`, `images`, `theme`, `colors.light`, …). No new
  request state (REQ-RUNTIME-002): the groups and the open tab are computed per page; the 1.13.1 boundary listener is
  untouched. New ports use plain PHP types only (REQ-ARCH-001).

## [1.13.1] - 2026-11-11

Target: 1.13.1 (patch).

### Fixed

- **Stale settings outside HTTP when the services resetter does not run (1.0.0 – 1.13.0):** outside a main request the
  `InstanceSettings` rows / image-metadata memo was built once and reused until `kernel.reset`, and the bundle's
  private entity manager (1.11.0+) was dropped only by `kernel.reset`. With the stock Symfony kernel the services
  resetter runs before every HTTP request (also with `FRANKENPHP_RESET_KERNEL=false`) and Messenger resets services
  between messages by default, so stock setups are not affected. A Messenger consumer started with `--no-reset`, a
  long-running console command, or a host that removes or bypasses `services_resetter` (or drives `http_kernel`
  directly) kept the values it read first and never saw a change saved by another process: for example an SMS or
  mail provider token rotated in the admin (SmsKit, MailKit read it from here) kept being used until the process
  exited. New `EventListener\WorkerBoundaryResetListener` resets the memo and drops the private manager (recreated
  on next use, also when it was closed) at the main `kernel.request` and `kernel.terminate`, Messenger
  `WorkerMessageReceived` / `Handled` / `Failed` and `console.command` / `console.terminate`. Its references are
  `IGNORE_ON_UNINITIALIZED_REFERENCE`: a boundary never builds the settings services. `kernel.reset` stays.
  Tests: `WorkerBoundaryResetTest` (one kernel, no resetter: 600 Messenger messages and 200 console commands
  alternating two keys, each changed by "another process" with raw SQL before the unit, closed private manager
  recovered, memory margin 4 MiB) and `WorkerBoundaryResetListenerTest`.

## [1.13.0] - 2026-11-11

Error page illustrations from the brand (Beacon 1.147), the LM-L3 colour fix and the 1.12 image-input follow-ups. Audit: Appendix LS and Delta LS, Pass.

### Added

- **Error page illustrations from the brand** (Beacon 1.147, UiKit 2.47 SPI): `Brand\BrandErrorIllustrationProvider`
  implements `Nowo\UiKitBundle\View\ErrorIllustrationProviderInterface`, registered as
  `nowo_instance_settings.brand.error_illustration_provider` with `brand.enabled`, `files.store: filesystem` and UiKit's
  interface present. New config `brand.error_illustrations` (`base`: an `extra_images` id such as `mascot`;
  `per_code`: default true). For status N it answers the uploaded `error_<N>` extra image, else the uploaded base image,
  else null (UiKit's `error_pages.illustrations` map); configured defaults never count. Resolved from the file on disk
  and the store's own metadata (hash, width, height), never the database or HTTP; file route URL, intrinsic size, no
  WebP source; readonly, worker-safe. `FilesystemSettingFileStore::storedRecord()` exposes that lookup. A `base` that
  is not a declared extra image, or set with `files.store: doctrine`, fails the build.
- **`web_ui.unlayered_styles`** (default false) and `public/css/nowo-instance-settings.unlayered.css`: the unlayered
  stylesheet for hosts that link UiKit's `nowo-ui.unlayered.css`; `settings/_styles.html.twig` takes `unlayered`.

### Changed

- The bundle stylesheet `nowo-instance-settings.css` sits in UiKit's cascade layer `nowo-ui` (with UiKit's layer order
  statement), so host utilities and unlayered host CSS win over it (audit LM29-I1). Visual change for hosts with
  unlayered global CSS. See [UPGRADING](UPGRADING.md#unreleased).
- The demo pins the bundle's path package as `1.13.0` (`versions` option) instead of the checked-out branch name
  (audit LM29-I4).

### Fixed

- **Brand appearance colours** (audit LM-L3): `AppearanceSection::filterValues()` kept dropping every submitted colour
  equal to the selected preset, so an untouched save, or switching the preset to one matching a stored colour, deleted
  the stored row. It now follows the 1.12 rule of colour fields: a colour re-submitted equal to its stored value is
  kept; a colour changed to the preset is still stored as null.
- **Filesystem store metadata after a cold read** (audit LS-L1): a lookup that had to read and hash an original (no
  `.variants/<field>.meta` after a checkout, or a file copied by a deployment) now writes the metadata, best effort and
  atomically (a temporary file renamed into place, bound to the stat taken before the read), so the next lookup (an
  error page, the file route) is one `stat()`. A read-only directory or any write failure only logs at debug level
  (`logger` service, new optional constructor argument) and the lookup answers as before.
- `BrandSection::EXTRA_ID_PATTERN` uses the `D` modifier (audit LS-I4): an `extra_images` id ending with a newline is
  refused by the configuration.
- **`image_input: false`** (audit LM29-I3): such an image field keeps its pre-1.12 row (label and help, no card), and
  the section page loads UiKit's `image-input` script and the bundle stylesheet only when a field shows the image
  input box (`SettingImageType::inputInForm()`).

## [1.12.0] - 2026-11-11

Colour setting field (SettingType::Color, preset-following) and one card per image field on UiKit 2.47's image input. See [UPGRADING](https://github.com/nowo-tech/InstanceSettingsBundle/blob/v1.12.0/docs/UPGRADING.md#to-1120).

Colour setting field and the image input box for image fields. See [UPGRADING](UPGRADING.md#to-1120).

### Added

- **`SettingType::Color` and `SettingField::color()`.** A `#rrggbb` colour, stored lower-cased; null (nothing stored)
  follows the field's preset colour: its default, or another field's value with
  `SettingField::withPresetFrom($field, [value => '#rrggbb'])`. A colour equal to its preset (for the submitted, else
  the stored, value of the source field) is saved as null, so a later change of the preset still reaches it. An invalid
  value is refused with `This value is not a #rrggbb colour.`; a colour field cannot be secret. `presetColor()` and
  `presetFrom()` read the preset; the registry refuses a preset source that is missing, secret or an image field.
- **Colour input in the admin form** (`Form\SettingColorType`, theme `@NowoInstanceSettingsBundle/Form/color.html.twig`):
  the hex text input is the submitted value, paired with a native swatch (`<input type="color">`, never submitted,
  labelled by the row label) and a "Use the preset colour" button, kept in sync both ways by
  `bundles/nowoinstancesettings/js/nowo-instance-settings-color.js` (plain script, deferred, request `csp_nonce`, no
  inline handler or style). The swatch shows the entered colour, else the preset (following a change of the source
  field live). Without JavaScript the swatch and the button stay hidden and the hex input works alone. Styles in
  `bundles/nowoinstancesettings/css/nowo-instance-settings.css` use UiKit tokens. The section page loads both only when
  the form has a colour row (`color_picker` template variable; partial `settings/_color_assets.html.twig`). New
  translation key `color.reset` in the 7 locales. The form shows a colour that is not stored as empty and a stored colour as
  stored (an untouched re-submit never deletes it, even when it equals the default); each reset button is described by
  its field label.

- **Image input box** (UiKit 2.47): each image field is one card holding UiKit's image input: the stored image, else
  the field's `default_url` (Brand and `extra_images`: the configured default asset) with a "Default" badge, a local
  preview of a picked or dropped file before saving, Replace / Remove / Undo in the box, the field label once (on the
  file input, also the preview's `alt`) and the help under it. Two cards per line from 40rem (bundle stylesheet, UiKit
  tokens). No-JS path unchanged (plain file input + remove checkbox). Options `image_input` (default true),
  `current_url`, `default_url`, `backdrop`; section form option `stored_image_urls`; template variable `image_input`
  (loads UiKit's `image-input` script) and partial `settings/_styles.html.twig`.

### Changed

- **Requires `nowo-tech/ui-kit-bundle` ^2.47** (image input).
- **Image fields**: the 1.5 preview list above the form is no longer rendered by the bundle template (the box shows the
  image); an image row carries no label of its own (the field label and help are on its file input).
- **Brand appearance colours** (`appearance.light_*` / `dark_*`) are `SettingType::Color` fields with the colour input;
  the swatch of an empty colour shows the colour of the selected preset of its mode. Stored values, the `Regex`
  constraint, labels, help, contrast notices and the export are unchanged.

## [1.11.1] - 2026-11-11

`SettingsChangedEvent` is dispatched after the commit, outside any transaction. See [UPGRADING](https://github.com/nowo-tech/InstanceSettingsBundle/blob/v1.11.1/docs/UPGRADING.md#to-1111).

### Fixed

- **`SettingsChangedEvent` was dispatched from inside the commit.** Inside an application transaction (a `save()` or an
  import wrapped by the host, or the bundle's own import transaction), the held event was dispatched from the DBAL
  middleware's commit, where the connection still reports the transaction as active: a listener that writes (an audit
  row flushed on the application manager) nested into a transaction that was ending. The outermost commit now only
  marks the events committed; they are dispatched at the next point where the bundle's connection reports no open
  transaction: the next settings event, a Doctrine `postFlush`, the end of the bundle's own import transaction, the
  end of the request / console command / Messenger message, or `SettingsPendingEventsInterface::dispatchPending()`.
  Rollback and savepoint rollback still drop them; `save()` outside a transaction still dispatches at once.
  Affected: 1.10.0 to 1.11.0. On PostgreSQL and MySQL a listener that wrote to the database failed and its row (for
  example an audit row) was lost; on PostgreSQL the connection was also left inside a transaction, so later writes in
  the same process (a kept-alive worker) were not committed. Workaround for older versions: do not write to the
  database from a `SettingsChangedEvent` listener when saving inside your own transaction, or save outside it.

### Added

- `Event\SettingsPendingEventsInterface` (public alias of the after-commit dispatcher): `dispatchPending()` and
  `countPending()`, for a host that wants the events right after its own commit.

## [1.11.0] - 2026-11-11

Settings are written through a private entity manager: a save no longer commits unrelated pending host changes or resets a host manager; doctrine.entity_manager is now a real dedicated-manager option. See [UPGRADING](https://github.com/nowo-tech/InstanceSettingsBundle/blob/v1.11.0/docs/UPGRADING.md#to-1110).

### Fixed

- **The bundle no longer flushes, clears or resets the application's entity manager.** `save()`, imports, both
  seeders, `SettingValueRepositoryInterface::apply()` / `transactional()` and the database file store used the default
  entity manager (`doctrine.entity_manager: null`), so a save also committed every change the application had left
  pending in it, and a failed write reset it. They now read and write through a private entity manager
  (`Doctrine\SettingsEntityManager`) created on the connection, configuration and event manager of the manager that
  maps `SettingValue`: only bundle rows are flushed, a failed write closes only that private manager (replaced on the
  next call, also in a kept-alive worker), and no application manager is reset any more. The connection is shared, so
  a transaction you open around a bundle call still includes the rows and holds `SettingsChangedEvent` until your
  outermost commit (as since 1.10).
- **`doctrine.entity_manager` is now real.** Before, the mapping was always prepended to the default manager, so a
  named manager did not map the entities unless the host mapped them by hand. With the option set, the entities (and
  the file entity with `files.enabled`) are mapped on that manager only, removed from every other manager (also with
  `auto_mapping: true` on the default one), the build fails clearly when the manager does not exist or does not map
  them, and the bundle manager never becomes the host's default one.

### Added

- `Repository\SettingRowWriterInterface::writeRows(list<StoredSettingRow> $save, list<string> $remove = [])` (public
  alias; default `RepositorySettingRowWriter` over the aliased repository): writes the raw store with DTOs, in one
  transaction, secrets encrypted at rest. The counterpart of `SettingRowReaderInterface`.
- `Doctrine\SettingsEntityManager` (`kernel.reset`), `DependencyInjection\Compiler\EntityManagerMappingPass`.

### Deprecated

- The entity-typed methods of `SettingValueRepositoryInterface` (`findAll()`, `findBySection()`, `apply()`) as a public
  API: use `SettingRowReaderInterface` / `SettingRowWriterInterface`. They keep working (the bundle still uses them
  internally) and become DTO-typed in 2.0.

### Changed

- `DoctrineSettingValueRepository` is no longer `readonly` and takes an optional third argument
  (`?SettingsEntityManager`); `DoctrineSettingFileStore` takes the same optional third argument. With
  `doctrine.entity_manager: null` the source manager is the one `doctrine` returns for `SettingValue` (the default
  manager in a standard application, as before).

## [1.10.0] - 2026-11-10

Target 1.10.0. DDD / hexagonal P2 fixes (audit 2026-11-09).

### Added

- `Repository\SettingRowReaderInterface` (`findAllRows()`, `findRowsBySection()`) returning the read-only DTO
  `Repository\StoredSettingRow` (`section`, `name`, `value`, `isSecret`, `updatedAt`, `key()`, `secret()`). The
  decrypted secret is kept outside the object's properties (audit KI-I-M1): no dump, cast, `var_export()`,
  VarDumper or Serializer shows it, `json_encode()` / `serialize()` mask it as `***`, and rows cannot be cloned. The default `RepositorySettingRowReader` maps the rows of the aliased
  `SettingValueRepositoryInterface`; the alias is public. The entity-based port is unchanged.
- `Event\AfterCommitEventDispatcher` and `Doctrine\TransactionObserverInterface`: the bundle's events go through a
  dispatcher that follows the real transactions of the bundle's connection.

### Changed

- `SettingsChangedEvent` (from `save()`, imports and both seeders) is dispatched **after the outermost commit** of the
  bundle's connection and dropped when the transaction, or a savepoint enclosing the write, rolls back. Without an open
  transaction it is dispatched at once, as before. Same event class and payload. A listener exception during a deferred
  dispatch is logged (`error`) instead of being thrown out of the commit.
- The DBAL middleware `FileStoreTransactionMiddleware` is registered with every file store (and without image files),
  not only with `files.store: filesystem`; its constructor (and the driver / connection ones) take any number of
  `TransactionObserverInterface` (the filesystem store implements it). Each observer is notified in isolation: one
  that throws no longer keeps the others from being told; the first exception is rethrown afterwards (audit KI-I-L2).
- The after-commit dispatcher keys its state weakly by connection: a connection closed mid-transaction no longer
  holds every later dispatch (audit KI-I-L1).

## [1.9.0] - 2026-11-10

Target 1.9.0. Request from Symfony Beacon (spec 222 "provider-dependent fields", owner answer Q4 = b).

### Added

- **`web_ui.hidden_secrets: drop|keep_typed`** (default `drop`, unchanged behaviour): with `keep_typed` the admin form
  saves a secret typed into a provider-dependent row hidden for the submitted provider (typed without JavaScript or
  before switching back). It is never `required`; its own constraints run; blank keeps and "clear" deletes. Hidden
  plain fields are still neither validated nor written. A hidden secret that fails its constraints refuses the save
  like any invalid field and is named in a notice above the form (`notice.hidden_secret_invalid`), as its row is
  hidden. Per section: `SettingsSectionHiddenSecretsInterface`
  (`getHiddenSecrets(): HiddenSecrets`). New enum `Section\HiddenSecrets`; new `SettingsSectionType` option
  `hidden_secrets`; `SettingsSectionType::keepHiddenFields()` takes an optional third argument `$keepTypedSecrets`.
- **`web_ui.hidden_secrets_notice`** (default `false`): while hidden secrets are dropped, a section with a
  provider-dependent secret shows a notice above its form (translation key `notice.hidden_secrets_dropped`, 7
  locales; overridable in the host translations, no template override).

### Documentation

- USAGE: new section "Using it from a DDD / hexagonal application" (where the bundle sits in a hexagonal host, the ports, events and DTOs to build an anti-corruption layer on, and the known limitations).

## [1.8.0] - 2026-11-09

Target 1.8.0: an opt-in crop step on image setting fields (owner decision D-20261109-09, host Symfony Beacon), on
UiKit 2.43's image crop. With the crop off (the default) the admin output is byte-identical to 1.7.1.

### Added

- **Crop step on image fields.** `files.crop: false|true|{shape, quality, labels}` turns UiKit's crop dialog on for
  every image field (brand `logo_light`, `logo_dark`, `mark`, `app_icon`, `favicon`, extra images and host fields);
  `SettingField::withCrop(array $options)` turns it on (or overrides the options) per field and
  `SettingField::withoutCrop()` turns it off; `SettingField::crop()` reads the setting. The file input of
  `SettingImageType` prints the attributes through UiKit's `ImageCrop::attributes()` (dialog texts translated); the
  field's own `accept` is kept.
- **Defaults derived from the field** (`Form\SettingImageCrop::derive()`): square fields crop with `aspect` 1, others
  `free` (or 1 when `max` is under 3 × `min`); `min` the field's larger minimum dimension, clamped to the smaller
  maximum one (`app_icon` 512); `max` the smaller maximum dimension, at most 2048 (logos 800); `format` `auto` when
  the field takes PNG, WebP and JPEG, else one it takes; `max_bytes` 95 % of `maxBytes`.
- **The bundle default never fails a page** (audit JP-M1): a field its options cannot crop (both maximum dimensions
  under 16 px, a minimum width over the maximum height, a circle on a field that cannot take aspect 1) is shown
  without them; `withCrop()` still refuses such a field when the section definitions load. Per-field `labels` merge
  with the bundle ones per text.
- **Options checked against the field** when the section definitions load (`SettingImageCrop::resolve()`): a format
  the field does not take (`auto` needs the three), `max_bytes` over `maxBytes`, a non-1 aspect on a square field
  (`1/1` and `1:1` count as 1), a fixed-aspect crop at `max` outside the field's dimensions or with its short side
  under `min`, a free crop with `max` over the smaller maximum dimension or under 3 × `min` (audit JP-L1), unknown
  options or bad values throw `InvalidArgumentException` naming the field; `files.crop` with other keys or bad values fails the container build.
- **The section page and the IS-1 embed load UiKit's `image-crop` script** when the current section has a cropped
  field (template variable `image_crop`, nonce from `csp_nonce`).
- `SettingImageType` options `crop` (null = bundle default, false, true or options) and `spec` (the field's
  `ImageSpec`); its constructor takes the bundle default and the translator.

### Changed

- Requires `nowo-tech/ui-kit-bundle` **^2.43** (was ^2.40) for `ImageCrop` and the `image-crop` runtime.

### Security

- The crop is a browser convenience: uploads still run the whole server pipeline (content sniffing, pixel cap, field
  limits, re-encoding). Pages with a cropped field need `img-src blob:` under a strict CSP (UiKit's decoding fallback).

## [1.7.1] - 2026-11-09

Target 1.7.1: a patch for hosts adopting 1.7.0 with `files.store: filesystem` (reported by Symfony Beacon). The
database store and the admin upload pipeline are unchanged.

### Fixed

- **`files.directory` and `seed.base_dir` accept `%env(...)%`.** 1.7.0 refused them at container build with
  `must be a local directory path, got ""` (Symfony validates an env placeholder with an empty string). The tree now
  lets that through; the extension still refuses a literal blank value, and the path rules move to run time for an env
  value: `FilesystemSettingFileStore` refuses a blank / `scheme://` / NUL directory and a directory under the public
  roots (`%kernel.project_dir%/public`, `brand.public_dir`) when it is built, with `File\PathGuard`, so audit
  JG-L4 / JG-L6 hold for env values too, including directories that do not exist yet; `SeedFileSeeder` refuses a
  non-local `seed.base_dir` when it is built (a blank one would have confined to the current directory). Both keys
  must be **absolute**, literal or from env (audit JK-I3: a relative path resolves against the current directory,
  which differs between PHP-FPM and the CLI). New `PathGuard::isLocalPath()` and `PathGuard::isAbsolute()`.
- **Lossless adoption of committed images (seed, filesystem store).** 1.7.0 re-encoded a committed file found at its
  stable path (audit JG-L3), so a JPEG lost quality and was rewritten with new bytes on every fresh database. The seed
  now uses `ImageProcessor::adopt()`: the same checks and decode as an upload, then `File\ImageMetadataStripper`
  removes metadata and trailing data without touching the pixels (JPEG `APPn` / comments / after `EOI`; PNG text,
  `eXIf`, `tIME`, `iCCP`, APNG and private chunks / after `IEND`, and the kept rendering chunks only at their spec
  length (audit JK-L1); WebP `ICCP` / `EXIF` / `XMP ` / trailing bytes, RIFF
  size fixed). A clean file is left byte for byte (no write, same mtime), so a second seed is a no-op on disk. It falls
  back to the re-encode when the structure cannot be stripped safely or a JPEG has an EXIF orientation. JG-L3 still
  holds: no EXIF / GPS / appended payload is stored or served. Admin uploads keep the re-encode.
- The filesystem store normalises its base directory lexically for reads and writes: a `files.directory` such as
  `…/missing/../files` failed on the first write in 1.7.0.

### Added

- The filesystem store writes `<section>/.variants/.gitignore` (`*`) so generated variants and metadata ignore
  themselves in git; an existing file there is never overwritten.

## [1.7.0] - 2026-11-09

Target 1.7.0: host brand seed file with images (IS-3) and an opt-in filesystem image store (owner decision
D-20261109-03). Hosts that set neither `seed` nor `files.store` keep the 1.6 behaviour (the database store is
unchanged byte for byte).

### Added

- `nowo:instance-settings:seed [file] [--section=…] [--dry-run]` and the public service `Seed\SeedFileSeeder`: load a
  versioned host seed file (`schema: nowo-instance-settings-seed`, `version: 1`; YAML or JSON) with plain values under
  `settings` and image fields under `files` as paths relative to the seed file. Fill-only (a stored value or image is
  never overwritten; a second run is a no-op), insert-only in one transaction with a re-check (a concurrent admin save
  wins), all-or-nothing validation before any write (unknown key / section / field, secret fields, wrong kind,
  invalid values, refused paths or images → `Seed\SeedFileException`, exit 1, names and reason codes only). Values go
  through the section save filter and the field validation; images through the upload pipeline (type sniffing,
  byte / dimension / square limits; SVG refused with `svg_not_supported`). Audited like a save: `Instance settings
  saved.` log line and `SettingsChangedEvent` (origin `seed`, with `fileChanges`) per section, after the commit.
- Image paths of a seed: relative only, no URL / stream wrapper / `data:` / NUL, resolved with `realpath()` and refused
  outside `seed.base_dir` (symlink escapes included).
- Host-side importer API: `SeedFileSeeder::seedFile()`, `::seed(array, directory)` and `::seedImage('<section>.<field>',
  path)` (fill-only); replacing an image stays `InstanceSettingsInterface::save()` with `ImageUpload::fromPath()`.
- Configuration `seed.file` (default seed file, null) and `seed.base_dir` (default `%kernel.project_dir%`).
- `files.store: filesystem` and `files.directory` (default `%kernel.project_dir%/config/instance-files`):
  `File\FilesystemSettingFileStore` keeps one stable file per image field (`<section>/<field>.<png|jpg|webp>`,
  variants in `<section>/.variants/<field>-<hash12>-<size>.png`) instead of database blobs, so uploads leave files
  ready to commit next to the seed file. Atomic writes (temporary file + `rename()`, 0644 / 0755 minus the umask),
  names from the field allowlist only, symlinked directories / targets refused, a replaced image of another type and
  old variants removed, a cleared field's file deleted, the file on disk authoritative (served under its current hash,
  so a replaced logo never hits a stale cache). `purge-files` removes stale variants and temporary files only.
  With the filesystem store the seed adopts a valid image that already sits at the field's stable path (re-encoded
  like an upload) instead of overwriting it with another file.
- Filesystem store, transactional (audit JG-M1): `File\StagedSettingFileStoreInterface`, `File\FileStoreTransaction`
  and the DBAL middleware `Doctrine\FileStoreTransactionMiddleware` (registered with `files.store: filesystem`). New
  files are staged as temporary files and clears queued; renames and deletes happen after the outermost commit (also
  of a transaction the host opened around the call) and a rollback discards them, so a failed save, clear, import or
  seed never changes the files. Saves, imports and seeds run in a file unit. The middleware is attached to the bundle's
  connection only (`DependencyInjection\Compiler\FileStoreMiddlewarePass`, audit JG-L7) and follows savepoints
  (`SAVEPOINT` / `RELEASE SAVEPOINT` / `ROLLBACK TO SAVEPOINT`, SQL Server `SAVE` / `ROLLBACK TRANSACTION`): changes
  inside a savepoint that is rolled back are dropped even when the outer transaction commits (audit JG-M3).
- Filesystem store metadata `<section>/.variants/<field>.meta` (hash, type, dimensions bound to the file's stat):
  lookups and wrong-hash requests no longer read and hash the file (audit JG-L1).

### Changed

- `InstanceSettings::save()` skips an image of the same hash as the stored reference only when the store holds it, so
  a re-upload or an import after a store switch writes the file again (audit JG-M2).
- The file route answers 404 (no-store) when the store returns no bytes for a record it found, instead of an empty
  immutable 200 (audit JG-L2); with the filesystem store it serves a field's file only while the field has a setting
  row (audit JG-L3, new optional `SettingFileController` argument).
- The container refuses a filesystem store directory under `%kernel.project_dir%/public` or `brand.public_dir`,
  whatever its spelling and also before it exists (`File\PathGuard`; audits JG-L4, JG-L6); the store checks again before
  creating it.
- `doctrine/dbal` is now required as `^4.0` (it was only allowed through `doctrine/orm`): the transaction middleware
  wraps DBAL 4's driver connection (`exec(): int|string`), and DBAL 4 nests transactions with savepoints by default.

- `InstanceSettings::getFile()` builds the `StoredSettingFile` hash and URLs from the store's record (identical for
  the database store; the current file for the filesystem store).
- `ImageUpload::fromPath()` refuses URLs and stream wrapper paths (`scheme://`, `data:`) when read (`upload_failed`).
- `symfony/yaml` is suggested (YAML seed files); JSON seed files need nothing.

## [1.6.0] - 2026-11-09

Target 1.6.0. Two asks from Symfony Beacon's brand adoption (IS-1, IS-2). Both are opt-in: hosts that do not use the
new service or option keep the 1.5 pages byte for byte. Audit: Appendix JE.

### Added

- **Embed sections in a host page (IS-1):** `Embed\SettingsSectionEmbedder::handle(Request, string $section,
  SectionEmbedOptions)` shows and saves one section inside a host route and layout (public service, autowirable).
  `Embed\SectionEmbedOptions::routes(['brand' => 'app_brand', …])` (one host route per section) or
  `::route('app_appearance', ['brand', 'appearance'])` (one route with a `{section}` parameter) give the nav
  allowlist and the host routes; `withTemplate()`, `withLayout()`, `withHeader()` (a UiKit `PageHeader`) and
  `withContext()` (extra template variables; the bundle's win) are optional.
  - Same checks as the bundle route, by the same code: the UI gate (`security.access_roles` or the custom checker),
    the section area (`security.area_roles.<section>`), the per-section CSRF token, secrets never rendered back,
    uploads through the image pipeline, provider-dependent fields. The bundle route now delegates to the embedder.
  - The allowlist only narrows: tabs are the allowlisted sections the user may open; a section outside it is a 404,
    one the user may not open is denied (403 / firewall entry point) as on the bundle route. The UI gate runs first
    (as the bundle route's access listener does), so a user without UI access gets the denial for any section name,
    known or not. The section page runs the UI gate itself on every path, also when called outside the bundle's
    access listener; `accessibleSections()` is empty for a user the UI gate denies (audit JE-L1).
  - After a save the user is redirected to the host route of the section, generated by the router from a route
    name; route names are checked (`SectionEmbedOptions::ROUTE_PATTERN`), so a path or a URL is refused and nothing
    from the request is used (no open redirect). Every host route is generated once at the start of `handle()`; an
    unknown route, a missing parameter or a URL that is not a local path (`/`, not `//`: another host or scheme) is
    a `LogicException` naming the section and the route (audit JE-L3, JE-I1).
  - A GET sub-request of a POST (a Twig `render(controller())` fragment, which would drop the save) is refused with a
    `LogicException`; call the embedder from the host route's controller or `forward()` (audit JE-L2).
  - The embedded page has no "Import / export" link, links its tabs to the host routes and carries
    `data-embedded="true"` and the template variable `embedded`.
- **Theme-card picker (IS-2):** `nowo_instance_settings.brand.preset_picker: cards` renders `appearance.preset` /
  `preset_dark` as a radio group of cards with a swatch of the preset palette (paper, surface, ink, accent, accent
  deep of the field's mode) and UiKit's preset names, instead of a select of ids. Default `select` (unchanged).
  - Generic: `SettingField::withThemeCards(array $swatches)` on any choice field, `Form\ThemeCardsType` (an expanded
    single `ChoiceType`) and its form theme `@NowoInstanceSettingsBundle/Form/theme_cards.html.twig`, added to a
    section form only when it has such a row.
  - Accessible: native radios inside labels (arrow keys, announced state, no JavaScript), `role="radiogroup"` named
    by the row label and described by the help. Swatches are inline SVG rectangles whose fill is a `#rrggbb` colour
    validated by the type (anything else is dropped; at most 8 per option): no style attribute, strict CSP safe.
  - Host presets whose readable names clash (`my-theme`, `my_theme`) keep their raw ids as labels; none is dropped.
  - UiKit 2.41 has no card-radio widget (its `AppearanceType` uses selects): this widget is a candidate to move to
    UiKit later.
- `BrandPalettes::swatch()`, `SettingsPageFactory::embeddedSection()` / `formThemes()`,
  `SettingsSectionEmbedder::accessibleSections()` (the allowlisted sections the user may open, for a host menu).

### Changed

- `SettingsController::section()` delegates to `SettingsSectionEmbedder` (new optional last constructor argument,
  wired by the extension). Output, status codes, flashes and redirects of the bundle route are unchanged. A controller
  built by hand with the 1.5 arguments builds the same embedder from its arguments and service locator.

## [1.5.0] - 2026-11-09

Brand bridge (spec 220 slice S3, target 1.5.0). Opt-in with `brand.enabled: true`: hosts that do not enable it keep
the 1.4 services, routes, forms, pages (byte for byte) and schema.

### Added

- `nowo_instance_settings.brand` (`enabled`, `public_dir`, `fallback_provider`, `defaults`, `extra_images`). With
  `enabled: true` the bundle registers two sections and the bridge:
  - `brand` (`Brand\BrandSection`): `name` (required, default `brand.defaults.name`, ≤ 80), `short_name` (≤ 12),
    `eyebrow` (≤ 120), `wordmark` (≤ 40), and the public image fields `logo_light`, `logo_dark` (≤ 2400 × 800),
    `mark` (square, 64–1024 px), `app_icon` (square, ≥ 512 px, variants 512 / 192 / 180), `favicon` (square, ≥ 32 px,
    variants 32 / 16) and one `extra_<id>` per `brand.extra_images` entry. Texts refuse control characters, bidi
    controls and line separators (the brand contract's rules). Uploads are PNG / JPEG / WebP only, on the 1.4 pipeline.
  - `appearance` (`Brand\AppearanceSection`): `preset` / `preset_dark` (UiKit preset ids, `custom` allowed), seven
    `#rrggbb` colours per mode (`light_accent` … `dark_surface`), `corner_style`, `border_strength`, `footer_fixed`.
    An empty colour follows the selected preset; a saved colour equal to it is dropped, so a later preset change still
    reaches untouched fields. Advisory WCAG contrast notices (owner decision Q-O8: warn, never refuse).
  - `Brand\InstanceBrandProvider`: implements `nowo-tech/brand-contracts` `BrandProviderInterface` (aliased; a host
    alias wins) and UiKit's `AppearanceProviderInterface`. Stored values over `brand.defaults` (texts, a translatable
    eyebrow `{key, domain}` that follows the viewer's locale, committed default assets that may be SVG / ICO) and over
    UiKit's appearance defaults. `isDefault` while nothing is stored. A store failure is logged and gives the defaults.
  - `Brand\InstanceBrandAssetReader` (`BrandAssetReaderInterface`): bytes of an uploaded asset (original or PNG
    variant) or of a config default file, for CID e-mails and PDFs.
  - `DependencyInjection\Compiler\BrandBridgePass`: points `nowo_ui_kit.appearance.provider` at the bridge when UiKit's
    appearance is enabled and the host set no provider (`nowo_ui_kit.appearance.provider_is_default`), in either order
    with UiKit's `BrandAppearanceProviderPass` (audit IS-I6). A host provider (config or a services alias) always wins.
  - Twig `nowo_brand()`: the contract `Brand`.
  - `Brand\BrandFallbackProviderInterface` (`brand.fallback_provider`): host values the two sections seed from
    (`nowo:instance-settings:seed-from-fallbacks`); colours equal to the preset are not seeded.
- `SettingField::withGroup('colors.light')` (`$group`): the admin row carries `data-nowo-instance-settings-group`.
- `Section\SettingsSectionSaveFilterInterface`: a section may rewrite the values of a save (form, API, import) before
  they are validated.
- Image field admin preview (deferred from 1.4, audit IO Q3), opt-in per field with
  `withFormOptions(['preview' => true])` (every brand image field has it): the section page shows the stored image by
  its file route URL in an auto-escaped `<img src>`; never bytes, `data:` or base64. `Ui\ImagePreview`.

### Changed

- Requires `nowo-tech/ui-kit-bundle` `^2.40` (was `^2.23`): the bridge uses `AppearanceLogos`, the private defaults
  provider and `provider_is_default`. `nowo-tech/brand-contracts` `^1.0` is suggested (required for `brand.enabled`).
- `brand.enabled: true` implies the files (`files.enabled`), decided in this bundle's own prepend (audit IO-I10). A
  `files.enabled` / `brand.enabled` prepended by another extension after this one now fails at compile time with a
  clear message instead of a Doctrine mapping error on the first image save.

### Security

- Audit IU (batch, Q-O5): Pass-Low. Fixed after it: the bridge leaves UiKit's provider alone with
  `nowo_ui_kit.appearance.brand: off` (IU-L1); a host `BrandProviderInterface` next to `brand.enabled` fails the build
  (IU-I2); default asset URLs carry `?v=<sha12>` (IU-I3). Documented: a host decorator of UiKit's `config_provider` is
  bypassed under the bridge (IU-I1). The brand is not memoised per request (IU-I4: hashing only, no query).
  Re-verification of this diff pending. See
  [SECURITY.md](SECURITY.md#brand-bridge-threat-model-15).

## [1.4.0] - 2026-11-08

Image setting fields (spec 220 slice S2): upload pipeline, database file store, file route, envelope v2, purge, audit.
Opt-in with `files.enabled: true`: hosts that do not enable it keep the 1.3 schema, routes, forms and version 1 exports.

### Added

- `SettingType::Image` and `SettingField::image($name, $label, $help, $types, $maxBytes, $maxWidth, $maxHeight,
  $minWidth, $minHeight, $square, $variants, $public, $required)` with its rules in `Section\ImageSpec` (PNG / JPEG /
  WebP subset, `maxBytes` ≤ 5 MiB, dimensions, square ±2 %, up to 8 PNG variants, `public`). Image fields cannot be
  secret, declare a default or use / control `visible_when`. A section with an image field fails to load without
  `files.enabled: true` or without `ext-gd` (PNG, JPEG, WebP).
- Upload pipeline `File\ImageProcessor`: type from the content only (magic bytes + `getimagesizefromstring()`),
  4096 × 4096 px cap (the default maximum dimensions), field limits and a memory budget from the header before decoding, animated WebP refused, APNG reduced to
  one frame, every image re-encoded with GD (PNG with alpha, JPEG q90 turned upright from EXIF, WebP q90), which drops
  EXIF / GPS / XMP / ICC and appended payloads; PNG variants largest first, never upscaled.
- `InstanceSettingsInterface::getFile(key): ?StoredSettingFile` (hash, type, dimensions, size, `url()`,
  `url($variant)`, `variants()`) and the Twig function `instance_setting_file()`. `save()` accepts
  `File\ImageUpload` / `UploadedFile` (store), `null` (remove) or the current reference (keep) for image fields.
- Database store: entity `FileEntity\SettingFile` (own Doctrine mapping `NowoInstanceSettingsFiles`, prepended only
  with `files.enabled: true`), table `<prefix>instance_setting_file`, content-addressed per field;
  `File\SettingFileStoreInterface` seam, `File\DoctrineSettingFileStore` default.
- File route `nowo_instance_settings_file` (`{files.path_prefix}/{section}/{field}/{hash12}[-{variant}].{ext}`, `GET` /
  `HEAD`), added only when a section declares an image field: stored type, `nosniff`, inline disposition,
  `default-src 'none'; sandbox`, `ETag` / 304 without reading the blob, immutable public cache or private cache,
  CORP, `no-referrer`, `noindex`; the same empty 404 for every miss and for private images the viewer may not open.
- Admin form widget `Form\SettingImageType` (file input + "remove" when an image is stored), inside the section's CSRF
  form and area role.
- Envelope version 2: image references in `settings` and, on request, `files` (base64) — export form checkbox
  `include_files` (checked by default, shown only with image fields), CLI `--include-files`; import re-validates every
  file (strict base64, per-field and total caps, declared hash and type, whole upload pipeline), all-or-nothing, new
  reason codes `invalid_files`, `invalid_file`, `file_too_large`, `files_too_large`, `file_hash_mismatch`,
  `missing_image_file`, `unexpected_file` (translated in the 7 locales).
- `nowo:instance-settings:purge-files [--dry-run] [--include-unknown-sections]`: clears the orphan mark of every
  referenced image, marks images no live setting references (fields removed from code) and deletes images orphaned for
  `files.orphan_retention` days, re-reading the references inside the delete transaction, so a referenced image is
  never deleted. Images of sections that are not registered are kept unless `--include-unknown-sections`.
- Configuration: `files.enabled` (default `false`, literal boolean; the container refuses to compile without GD
  when it is on), `files.path_prefix` (default `/_settings-file`, must be outside `web_ui.path_prefix`),
  `files.orphan_retention` (default 30 days), `portability.max_file_bytes` (default 8 MiB).
- Audit: the "Instance settings saved." log line lists image changes (12-character hash prefix, type, dimensions,
  size); `SettingsChangedEvent::$fileChanges` (`field => {old, new}` hashes). Never content.
- `docs/SECURITY.md`: image fields threat model (IF1–IF16) with the tests that cover each threat; CDN (query string
  in the cache key, purge on withdrawal) and firewall (private images) notes in USAGE / CONFIGURATION.

### Changed

- `PortabilityEnvelope::VERSION` is 2; `PortabilityEnvelope::header()` takes the version; exports without image fields
  still write version 1. A version 1 file carrying `files` is refused (`unknown_key:files`).
- `InstanceSettingsInterface::get()` / `getString()` return null for image fields and `getSection()` leaves them out.
- Dev image: the Docker image installs GD (PNG, JPEG, WebP); CI enables the `gd` extension.

## [1.3.1] - 2026-11-02

required check on empty controllers (CT N4), empty_data in visibility (CT N5)

### Fixed

- Audit CT N4: `InstanceSettings::save()` decides `required` on provider-dependent fields with a controlling value
  saved empty (`null`, `''`, blanks; choice or bool) replaced by the controller's default, the value it takes once its
  row is deleted. `save(['provider' => null, 'smtp_host' => null])` with default `smtp` is refused, in any key order;
  through the admin form, a blank provider placeholder plus "clear" on the default provider's required hidden secret
  is refused (422) and the secret stays stored.
- Audit CT N5: `SettingsSectionType::submittedValue()` applies `empty_data` to an empty submission (after `trim`,
  like `Form::submit()`), so the `PRE_SUBMIT` listener sees the same controlling value as the form data.

## [1.3.0] - 2026-11-02

provider-dependent fields (visible_when), clear on hidden secrets, hidden-required rule

### Added

- Provider-dependent fields: `SettingField::withVisibleWhen(string $field, iterable $in)` (or the constructor
  argument `visibleWhen: ['field' => 'provider', 'in' => ['smtp']]`) shows a field only while another field of the
  same section holds one of the values. Validated when the section definitions load (the controlling field exists in
  the section, is not secret and not itself provider-dependent; choice / bool values must be valid values).
  `SettingField::isVisibleFor()`, `SettingField::visibilityValue()`.
- The admin form renders the option through the UiKit 2.23 `visible-when` row attributes (`row_attr`) and the section
  page root gets `data-controller="visible-when"` when a row uses it: hidden rows are `hidden` + `inert`, never
  disabled nor cleared. No host template change; the host registers the UiKit `visible-when` Stimulus peer.
- Server rules: a save validates and writes only the fields visible for the **submitted** controlling value; a
  hidden field never loses its stored row ("empty = delete" applies to visible fields only); a hidden field's
  submitted value is replaced by its current one before validation; secrets keep "blank = keep". Exception: an
  explicit "clear" on a hidden secret deletes its stored row (never writes a value), so an inactive provider's leaked
  credential can be removed without activating it. Visibility uses the controlling value normalized as the form type
  does (trim, then its view / model transformers: checkbox → bool, integer → int, choice → choice value) in both the
  `PRE_SUBMIT` listener
  (`SettingsSectionType::submittedValue()`) and the save.
- `InstanceSettings::save()` and `ValueNormalizer::normalize(..., bool $enforceRequired = true)`: `required` is not
  enforced on a provider-dependent field hidden for the saved + effective values; visibility is decided on the
  normalized saved values merged over the stored ones, so a padded / loosely typed controller (`' smtp '`, `'yes'`,
  `'01'`) cannot skip a required field, whatever the key order.
- Demo: the `sms` section uses `visible_when`; Playwright e2e (provider switch, save, switch back, axe in both states).

### Changed

- Requires `nowo-tech/ui-kit-bundle` ^2.23.
- `composer test` / `test-coverage` run PHPUnit with `-d memory_limit=512M`.

### Unchanged

- `nowo:instance-settings:seed-from-fallbacks`, the API (`InstanceSettings::save()`), imports and exports store every
  given field (only `required` follows visibility). Pages without `visible_when` render as in 1.2.

## [1.2.0] - 2026-11-02

DB-only settings: nowo:instance-settings:seed-from-fallbacks copies section fallbacks (env / YAML) into the store once (insert-only, secrets encrypted, audited, names-only output), TransactionStateGuard for service-calling migrations, section notices. See [UPGRADING](https://github.com/nowo-tech/InstanceSettingsBundle/blob/v1.2.0/docs/UPGRADING.md#to-120).

### Added

- `SectionFallbackProviderInterface` / `SettingFallback`: a section exposes the bootstrap values it falls back to
  (bundle configuration, usually `%env()%`).
- `FallbackSeeder` (public service) and `nowo:instance-settings:seed-from-fallbacks` (`--section`, `--dry-run`,
  `--strict`): copy the fallbacks into the store only where nothing is stored; idempotent; secrets encrypted; audited
  with the new origin `SettingsChangedEvent::ORIGIN_SEED` after the commit; insert-only (a concurrent admin save
  always wins); output, report and log list field names only. Seed once, then remove the fallbacks. See
  [UPGRADING](UPGRADING.md#to-120).
- `SettingFallback` keeps its value out of its properties (`value()`): `json_encode`, `var_export` and dumps mask a
  secret, serialization is refused.
- `TransactionStateGuard`: the seeder refuses to write on a phantom DBAL transaction (MySQL DDL in a transactional
  migration) with an actionable message.
- `SettingsSectionNoticesInterface` and `SettingsPageFactory::notices()`: section warnings rendered as UiKit warning
  alerts above the section form (`notices` template variable).

## [1.1.1] - 2026-11-01

Docs/demo follow-ups: SECURITY audit grades, demo install race, ui-kit ^2.21, absolute UPGRADING links in release notes See [UPGRADING](UPGRADING.md#to-111).

### Changed

- Requires `nowo-tech/ui-kit-bundle` ^2.21 (was ^2.17.3); the lock resolves UiKit 2.21.0 and DoctrineEncrypt 2.10.1.

### Fixed

- Demo: `make up` waits for the entrypoint's first `composer install` (the server answering) before it runs
  `composer install` again, instead of racing it on the shared `vendor/`.
- Release notes link UPGRADING with an absolute URL (`release.yml` rewrites relative `UPGRADING.md` links to the tagged
  file); relative links broke on the GitHub release page.

### Documentation

- SECURITY.md records the AI security audit grades (Appendix BY: first pass Pass (conditional) / Medium, final Pass
  (good) / Low, every finding fixed before 1.0.0) instead of "pending".
- CHANGELOG / UPGRADING: removed the release-candidate leftovers and merged the duplicate "To 1.0.0" sections; README
  coverage figures refreshed.

## [1.1.0] - 2026-10-31

Secret field validation errors are shown next to the field; section intros accept a Label or TranslatableMessage with parameters. See [UPGRADING](https://github.com/nowo-tech/InstanceSettingsBundle/blob/main/docs/UPGRADING.md#to-110).

### Added

- `SettingsSectionInterface::getIntro()` may return a UiKit `Label` or a Symfony `TranslatableMessage` (key +
  parameters, optional domain) besides a translation key; implementations returning `?string` stay valid.

### Fixed

- A violation on a secret field (e.g. a refused DSN) is now shown next to the field. DoctrineEncrypt's `SecretType` is
  compound, so its errors bubbled to the root form, which the settings scaffold does not render (the page answered 422
  without a visible message); the section form sets `error_bubbling: false` on secret fields.

## [1.0.0] - 2026-10-31

First release: settings store with tagged sections, encrypted secrets (DoctrineEncrypt ^2.10), UiKit/FormKit admin with per-section access, context-bound sealed config portability (export/import, CLI). See [UPGRADING](https://github.com/nowo-tech/InstanceSettingsBundle/blob/main/docs/UPGRADING.md#to-100).

### Added

- **Sections**: `SettingsSectionInterface` / `AbstractSettingsSection` (autoconfigured, tag
  `nowo_instance_settings.section`, order with `#[AsSettingsSection(priority:)]`), `SettingField` value objects
  (string, text, email, url, bool, int, float, choice, secret; defaults, labels, help, required, Symfony constraints,
  `notPortable()`, form options), definition validation, optional `SettingsSectionActionsInterface` for extra actions.
- **Store**: `InstanceSettingsInterface` (`get`, `getString`, `getInt`, `getFloat`, `getBool`, `getSecret`, `hasSecret`,
  `isOverridden`, `getSection`, `save`) backed by one Doctrine entity / table `instance_setting_value`
  (`doctrine.table_prefix`, entity manager selectable); one query per request, memo bound to the main request,
  `kernel.reset` and post-save invalidation; all-or-nothing validation (`InvalidSettingValueException`);
  `SettingsChangedEvent` (field names only); Twig `instance_setting()` (secrets refused).
- **Secrets**: `#[Encrypted]` column through DoctrineEncryptBundle (fail-closed compiler pass when it is missing);
  never rendered back in forms (DoctrineEncrypt `SecretType`, blank keeps, checkbox clears).
- **Admin UI**: UiKit settings scaffold pages (tab per section, import / export page), FormKit types
  (`SettingsSectionType`, `PortabilityExportType`, `PortabilityImportType`, profile `instance_settings`), CSRF on every
  mutation with a fail-closed compiler pass, `security.access_roles` / `area_roles` / `access_checker` /
  `allow_unauthenticated` (debug-only, literal booleans, empty lists refused), access listener on the prefix and on
  every bundle controller, firewall-only token trust, opt-in route loader `nowo_instance_settings` (controller not
  autoconfigured), Twig override paths, translations in en, es, fr, de, it, pt, nl.
- **Config portability**: versioned JSON envelope (`schema` configurable, `version`, `exported_at`, `settings`,
  sealed `secrets`, `providers`), `PortabilityProviderInterface` (autoconfigured, tag
  `nowo_instance_settings.portability_provider`), `ConfigPortability` export / import (strict validation,
  credential-shaped keys refused, dry run, single transaction), `DoctrineEncryptSecretSealer` (`enc:v2:`, context-bound, fails closed
  on another key), size-capped `JsonUploadReader`, CLI `nowo:instance-settings:export` / `nowo:instance-settings:import`.
- `Section\SectionActionContext`, `Routing\InstanceSettingsRoutes`.
- `--force` option of `nowo:instance-settings:export` (replace an existing regular file).
- Flex recipe, Symfony 8.1 FrankenPHP demo (MySQL, three sections, Playwright e2e + screenshots), docs, Spec Kit
  baseline, CI matrix PHP 8.2–8.5 × Symfony 7.4 / 8.1 with a 100 % coverage gate.

### Security

Fixes of the REQ-SEC-004 audit (Appendix BY, Pass (conditional) / Medium on `94a1569`):

- **M1** The web export / import only covers the sections the user may open; an import touching another section is
  refused as a whole (`forbidden_section`). `security.area_roles` restricting sections now requires an explicit
  `_portability` entry (compile error) unless the import / export page is disabled or a custom checker is used. The CLI
  keeps full access.
- **L1 / I7** Stored secrets carry a terminator: a secret ending with DoctrineEncrypt's `<ENC>` marker, or equal to
  `'0'`, is now encrypted and read back unchanged; a value that was not decrypted (wrong / lost key) reads as no secret.
- **L2** Sealed secrets are `enc:v2:` and bound to the schema and `<section>.<field>`; a value moved to another field
  or application is refused (`secret_context_mismatch`). (`enc:v1:` was never released.)
- **L3** Credential-shaped keys are matched on normalised keys (camelCase, `-`, `.`) and credential words anywhere in
  the key, on import and on provider **exports** (an export with such a key is refused).
- **L4** CLI export files are created in a private directory, set to 0600 and renamed into place; symbolic links are
  refused and an existing file is replaced only with `--force`.
- **I3** A `security: false` firewall on the prefix gets a plain 403 instead of an unhandled Security exception.
- **I4** Non-finite floats (`1e999`) are refused with a validation error instead of failing on flush.
- **I8** The demo publishes its port on 127.0.0.1 only.

Re-verification (BY: Pass (good) / Low) residuals:

- **R1** Web imports refuse forbidden sections / providers from the raw payload keys (`settings`, `secrets.values`,
  `providers`) before any value is validated or any secret unsealed: a refused file reveals nothing about them.
- **R2** Credential-key filter also matches `jwt`, `mfa`, `seed`, `webhook` key parts; documented as a heuristic.
- **R4** Requires `nowo-tech/doctrine-encrypt-bundle` ^2.10 (`on_decrypt_failure`, `generate_missing_key`); the sealer
  refuses an unauthenticated MysqlAes profile (`unsupported_encryptor`). The secret terminator stays.

### Changed (extension API, before 1.0.0)

- `SettingsSectionActionsInterface::getActions(SectionActionContext $context)` receives the section name, the current
  request and the user.
- Public route names in `Routing\InstanceSettingsRoutes` (`INDEX`, `SECTION`, `PORTABILITY`, `EXPORT`, `IMPORT`);
  `SettingsPageFactory::ROUTE_*` alias them.
- `SettingField::email()`, `url()`, `bool()` and `choice()` accept `constraints`.
- The access checker (`InstanceSettingsAccessCheckerInterface`, public alias) and `AccessGuard` are registered also with
  `web_ui.enabled: false`, so kits can authorise their own actions and commands; without SecurityBundle the role
  checker denies. `security.allow_unauthenticated` is refused outside `kernel.debug` in that case too.
