# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.2.0] - 2026-11-02](#120---2026-11-02)
- [[1.1.1] - 2026-11-01](#111---2026-11-01)
- [[1.1.0] - 2026-10-31](#110---2026-10-31)
- [[1.0.0] - 2026-10-31](#100---2026-10-31)

## [Unreleased]

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
