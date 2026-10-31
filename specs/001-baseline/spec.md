# Feature Specification: InstanceSettingsBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-10-31  
**Status**: Active  

**Package**: `nowo-tech/instance-settings-bundle`  
**Configuration root**: `nowo_instance_settings`  
**Code inventory**: [`code-inventory.md`](code-inventory.md)  
**Integrator spec**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md)

---

## Summary

The bundle gives Symfony apps one runtime settings store split into sections contributed by the host and by other
bundles (mailer, SMS, Mercure…): typed getters with defaults, secret fields encrypted at rest with
DoctrineEncryptBundle, a UiKit admin UI (one page per section, FormKit forms, CSRF, roles) and a versioned JSON
export / import (web and CLI) where secrets are left out or sealed with the instance key. Every read costs one query per
request and is safe in FrankenPHP worker mode.

---

## User Scenarios

### US-1 — Contribute a section (P1)

**Given** a service implementing `SettingsSectionInterface` (autoconfigured), **When** the container compiles,
**Then** the section gets a tab under `/admin/settings`, typed getters `<section>.<field>` and an export entry, ordered
by `#[AsSettingsSection(priority)]`; invalid or duplicate names fail on first use with the class in the message.

### US-2 — Read with defaults (P1)

**Given** a field with a default and no stored value, **When** code calls `get()` / `getString()` / `getInt()` /
`getFloat()` / `getBool()`, **Then** the default is returned; after a save the stored value is returned on the next
request in every worker; the first read of a request costs one query for all rows.

### US-3 — Edit a section (P1)

**Given** an admin (any role of `security.access_roles`, or the area roles of the section), **When** they submit the
section form with a valid CSRF token, **Then** the values are validated as a whole (types, required, e-mail / URL /
choice, field constraints) and stored, a success flash is shown and `SettingsChangedEvent` is dispatched; invalid
values are shown on their fields (422) and nothing is stored; a forged token gives 422 and nothing is stored.

### US-4 — Secrets (P1)

**Given** a `SettingField::secret()` field, **When** an admin types a value, **Then** it is stored encrypted at rest
(DoctrineEncrypt marker in the column), never rendered back in the form, kept when the field is left blank and removed
with the clear checkbox; `get()`, `getSection()` and Twig refuse it, `getSecret()` returns it decrypted.

### US-5 — Access (P1)

**Given** an anonymous user inside a firewall, **Then** the UI answers the firewall entry point (401 / login); a user
without the roles gets 403; tabs of sections the user may not open are hidden and their URLs answer 403; an empty role
list is refused at compile time; `allow_unauthenticated` is refused without `kernel.debug`.

### US-6 — Export (P1)

**Given** the import / export page or `nowo:instance-settings:export`, **When** an admin exports, **Then** a JSON
envelope (`schema`, `version`, `exported_at`, `settings`, `providers`) holds every portable plain value; secrets only
with `include_secrets`, only sealed (`enc:v1:`); the response is `private, no-store`, the CLI file mode 0600.

### US-7 — Import (P1)

**Given** an envelope, **When** it is uploaded (size-capped) or passed to `nowo:instance-settings:import`, **Then** it
is validated as a whole (schema, version, keys, sections, fields, portability, values, sealed secrets with this
instance key, provider data without credential-shaped keys, provider validation) and applied in one transaction; a
dry run only reports the keys; any refusal changes nothing and names the key, never the value.

### US-8 — Opt-in routes (P1)

**Given** a host whose routing is only `routing.controllers`, **When** the container is built, **Then** no bundle
route exists; the `nowo_instance_settings` loader import mounts them under `web_ui.path_prefix`.

---

## Functional Requirements

### Bundle and DI

- **FR-BUNDLE-001**: The bundle class registers `TwigPathsPass`, `CsrfProtectionPass`, `UnauthenticatedAccessPass`, `SecretEncryptionPass` and `SettingsSectionPass` (before optimization, priority 10) and exposes the `nowo_instance_settings` extension.
- **FR-CFG-001**: Strict configuration tree with safe defaults (`/admin/settings`, `ROLE_ADMIN`, `allow_unauthenticated: false`, sealed secrets allowed, 1 MiB cap, no prefix); invalid prefix, path, template page id, empty role lists, schema and upload cap fail with the path in the message.
- **FR-CFG-002**: The extension registers the registry, repository, prefix listener, normalizer, store (`kernel.reset`, public alias `InstanceSettingsInterface`), Twig extension, sealer, reader, portability service, CLI commands and route loader; with the UI on also the form types, page factory, access checker / guard / listener and the controller (`autoconfigure: false`, `controller.service_arguments`, `container.service_subscriber`); security switches must be literal booleans; the UI requires SecurityBundle unless `allow_unauthenticated`.
- **FR-CFG-003**: Autoconfiguration: `SettingsSectionInterface` → `nowo_instance_settings.section`; `#[AsSettingsSection]` → tag priority; `PortabilityProviderInterface` → `nowo_instance_settings.portability_provider`; `SettingsSectionPass` collapses duplicate section tags to the highest priority.
- **FR-CFG-004**: Prepends the Doctrine attribute mapping of `Entity/` and the FormKit profile `instance_settings` (unless the host defines it).
- **FR-CFG-005**: Fail-closed passes: no DoctrineEncrypt subscriber; UI without form CSRF / token manager; `allow_unauthenticated` without `kernel.debug`.
- **FR-TWIG-001**: `TwigPathsPass` prepends `templates/bundles/NowoInstanceSettingsBundle` (when present) and appends the bundle views under `@NowoInstanceSettingsBundle`.

### Sections and store

- **FR-SEC-001**: `SettingField` (named constructors, `notPortable()`, `withFormOptions()`, `labelKey()`, `matchesType()`) validates name, secret type, secret default, choices and default type; `SettingType` lists the types and which are strings.
- **FR-SEC-002**: `SettingsSectionRegistry` keeps sections in tag order, validates unique lower-snake-case section names and unique field names, resolves `<section>.<field>` keys and throws `UnknownSettingException` for unknown names.
- **FR-SEC-003**: `AbstractSettingsSection` defaults (label `<name>.label`, domain `messages`, no intro, no icon); `SettingsSectionActionsInterface` adds UiKit actions after the submit button; `#[AsSettingsSection(priority)]`.
- **FR-STORE-001**: `InstanceSettings` returns stored values else defaults (stale types fall back to the default), typed getters throw `LogicException` on a type mismatch, `get()` refuses secrets and `getSecret()` refuses plain fields, `getSection()` omits secrets.
- **FR-STORE-002**: Reads load every row in one query, memoized for the current main request (weak reference), reloaded for a new main request, cleared by `reset()` and after saves.
- **FR-STORE-003**: `save()` validates all values (`ValueNormalizer`) before writing, throws `InvalidSettingValueException` (field => messages, no values), removes rows for null values (default again), writes only changes with one flush, logs names, dispatches `SettingsChangedEvent` (section, changed fields, secret fields, origin).
- **FR-STORE-004**: `ValueNormalizer` trims strings (not text), maps blank to null, coerces numeric and boolean strings, enforces required-without-default, http(s) URLs, e-mail / URL / choice constraints and field constraints (when a validator is present).
- **FR-ORM-001**: `SettingValue` (section, name, JSON value, `#[Encrypted]` secret value, updated at, unique section+name) and `DoctrineSettingValueRepository` (EM per call, closed EM reset, `HINT_REFRESH`, single-flush apply, transactional).
- **FR-ORM-002**: `TablePrefixListener` prefixes the bundle table and index names only, no-op without prefix.
- **FR-TWIG-002**: `instance_setting()` returns plain values and refuses secrets.

### Admin UI

- **FR-UI-001**: `InstanceSettingsRouteLoader` (`type: nowo_instance_settings`) reads the controller `#[Route]`s, prepends the prefix, skips portability routes when disabled, loads once.
- **FR-UI-002**: `SettingsController`: index redirects to the first accessible section (empty state otherwise); section page builds `SettingsSectionType`, saves with CSRF, maps violations to fields, 404 for unknown sections, 403 per area; portability page; export (POST + CSRF, attachment, `private, no-store`, export failure flash); import (form validation, reader, dry run, reason-coded flashes); portability actions 404 when disabled.
- **FR-UI-003**: Form types are FormKit types (profile `instance_settings`): `SettingsSectionType` (widgets per type, secrets via DoctrineEncrypt `SecretType` with only a stored flag), `PortabilityExportType` (`include_secrets` when allowed), `PortabilityImportType` (JSON `FileType` with size cap, dry run).
- **FR-UI-004**: `SettingsPageFactory` / `SettingsPages` build UiKit `SettingsPage` view models (tabs, current form, actions, header portability action, empty state, portability page) and resolve layout / template overrides; templates extend the UiKit settings scaffold.
- **FR-ACC-001**: `ConfigurableInstanceSettingsAccessChecker` (any role; area roles replace; empty or anonymous deny), `AllowAllInstanceSettingsAccessChecker`, custom checker alias; `AccessGuard` trusts the token only with a firewall context and denies with a Security exception inside a firewall, 403 otherwise; `InstanceSettingsAccessListener` (kernel.request, priority 7) guards the prefix and bundle controllers anywhere.

### Portability

- **FR-PORT-001**: `PortabilityEnvelope` header and validation (schema, version 1, known keys).
- **FR-PORT-002**: `ConfigPortability::export()` (portable plain values, providers, optional sealed secrets, section filter, logging).
- **FR-PORT-003**: `ConfigPortability::import()` (validate all — sections, fields, secrets in clear, non-portable, values, sealed secrets, credential-shaped provider keys, provider validation — then one transaction; dry run; `ImportResult`).
- **FR-PORT-004**: `DoctrineEncryptSecretSealer` seals with `EncryptUtil` (`enc:v1:`) and fails closed on malformed, unmarked or foreign-key values; `SecretSealerInterface`.
- **FR-PORT-005**: `JsonUploadReader` reads at most `max + 1` bytes from an upload or a path, depth 64, object root; `PortabilityException` reason codes with optional key.
- **FR-PORT-006**: CLI `nowo:instance-settings:export` (sections, sealed secrets, output file 0600) and `nowo:instance-settings:import` (dry run), non-zero exit with the reason code.

---

## Success Criteria

- **SC-001**: PHPUnit green on PHP 8.2–8.5 × Symfony 7.4 / 8.1 with 100% statement and element coverage of `src/` (`composer test-coverage-100`).
- **SC-002**: PHPStan level 8 with FrankenPHP rulesets, `ignoreErrors: []`, 0 errors; Igor 0 KO.
- **SC-003**: `AdminUiTest` proves US-3…US-7 in a real kernel (SecurityBundle, Doctrine on SQLite, DoctrineEncrypt Halite); `RoutesOptInTest` proves US-8; `InvalidConfigurationTest` the fail-closed checks; `PageContractTest` the UiKit page contract.
- **SC-004**: Demo Playwright e2e green (`make test-e2e`).
- **SC-005**: Code inventory maps **57/57** files under `src/`.

---

## Key Entities

`SettingField` · `SettingsSectionInterface` · `SettingValue` (table `instance_setting_value`) · `SettingsChangedEvent` · export envelope · `ImportResult`.

## Non-goals

Kit-specific sections (mailer, SMS, Mercure, ops defaults), per-user / per-tenant settings, history storage, cross-request caches, outbound HTTP.

## Assumptions

Hosts configure DoctrineEncrypt (default profile) and keep its key out of the repository, put the prefix behind a firewall with `access_control`, and create the table with migrations.

## Validation commands

```bash
composer test-coverage-100
composer phpstan
composer igor
make release-check
make test-e2e
```
