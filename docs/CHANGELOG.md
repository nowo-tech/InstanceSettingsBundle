# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.4.0] - 2026-11-08](#140---2026-11-08)
- [[1.3.1] - 2026-11-02](#131---2026-11-02)
- [[1.3.0] - 2026-11-02](#130---2026-11-02)
- [[1.2.0] - 2026-11-02](#120---2026-11-02)
- [[1.1.1] - 2026-11-01](#111---2026-11-01)
- [[1.1.0] - 2026-10-31](#110---2026-10-31)
- [[1.0.0] - 2026-10-31](#100---2026-10-31)

## [Unreleased]

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
