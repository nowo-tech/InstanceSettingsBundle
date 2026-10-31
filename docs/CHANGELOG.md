# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `SettingsSectionInterface::getIntro()` may return a UiKit `Label` or a Symfony `TranslatableMessage` (key +
  parameters, optional domain) besides a translation key; implementations returning `?string` stay valid.

### Fixed

- A violation on a secret field (e.g. a refused DSN) is now shown next to the field. DoctrineEncrypt's `SecretType` is
  compound, so its errors bubbled to the root form, which the settings scaffold does not render (the page answered 422
  without a visible message); the section form sets `error_bubbling: false` on secret fields.

## [1.0.0] - 2026-10-31

First release: settings store with tagged sections, encrypted secrets (DoctrineEncrypt ^2.10), UiKit/FormKit admin with per-section access, context-bound sealed config portability (export/import, CLI). See [UPGRADING](UPGRADING.md#to-100).

First release candidate (1.0.0): instance settings extracted and generalised from the symfony-beacon host app
(`src/Shared/Settings`, config portability).

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
