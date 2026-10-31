# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
  credential-shaped keys refused, dry run, single transaction), `DoctrineEncryptSecretSealer` (`enc:v1:`, fails closed
  on another key), size-capped `JsonUploadReader`, CLI `nowo:instance-settings:export` / `nowo:instance-settings:import`.
- Flex recipe, Symfony 8.1 FrankenPHP demo (MySQL, three sections, Playwright e2e + screenshots), docs, Spec Kit
  baseline, CI matrix PHP 8.2–8.5 × Symfony 7.4 / 8.1 with a 100 % coverage gate.
