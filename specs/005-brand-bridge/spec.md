# Feature Specification: brand bridge (spec 220, slice S3)

**Feature Branch**: `feat/brand-s3`  
**Created**: 2026-11-08  
**Status**: Implemented, awaiting the batch security audit (target 1.5.0)  

**Package**: `nowo-tech/instance-settings-bundle`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md), [`../004-image-fields/spec.md`](../004-image-fields/spec.md)  
**Contract**: workspace spec 220 "Brand fully configurable from the bundles", §2.2, §3, §4.2, §6, §7.1 and the S3 row
of §8; owner decisions of 2026-11-08 (new `nowo-tech/brand-contracts` package, explicit opt-in bridge that takes
UiKit's provider over only when the host set none, database blobs, no SVG upload, warn-only contrast, one brand per
instance); audits IO (Q3 admin preview, IO-I10 prepend order), IS (IS-I4 translated eyebrow, IS-I6 bridge decision) and
IP (contract value-object rules).

---

## Summary

An instance stores its brand (name, texts, logos, icons, extra images) and its look (presets, seven colours per mode,
corner style, border strength, fixed footer) as settings in two sections, `brand` and `appearance`, enabled with
`nowo_instance_settings.brand.enabled: true`. The bundle serves them through the brand contract
(`BrandProviderInterface`, `BrandAssetReaderInterface`) to UiKit, Pwa, MailKit, LegalKit, SeoKit and host renderers,
and becomes UiKit's appearance provider when the host set none. Hosts that do not enable it see no change.

## User Scenarios

### US-1 — Rebrand without a deploy (P1)

**Given** `brand.enabled: true`, **When** an admin of the `brand` area uploads a light logo and changes the name,
**Then** `BrandProviderInterface::getBrand()` returns the new name and an uploaded `logo_light` asset with the file
route URL, UiKit's header shows the logo, and the section page previews the stored image by its URL.

### US-2 — Colours that follow the preset (P1)

**Given** the `appearance` section, **When** an admin picks the `ocean` preset and changes only the light text colour,
**Then** every other colour follows `ocean`; saving a colour equal to the preset's stores nothing; picking another preset
later changes every untouched colour; a contrast below WCAG AA shows a notice and saves anyway.

### US-3 — Nothing changes until opted in (P1)

**Given** a 1.4 host that upgrades without `brand.enabled`, **Then** no section, service, alias or Twig function is
added, UiKit's appearance provider is untouched (even with `appearance.brand: auto`), and the admin pages render byte
for byte as before.

### US-4 — Migrate a host appearance entity (P2)

**Given** a host `BrandFallbackProviderInterface` returning its legacy values, **When** the operator runs
`nowo:instance-settings:seed-from-fallbacks --section=brand --section=appearance`, **Then** the values that differ from
the defaults are inserted once (colours equal to the preset are skipped) and later runs change nothing.

## Requirements

- **FR-001** `brand.enabled` (literal boolean, default false) registers the two sections, `InstanceBrandProvider`
  (aliases `BrandProviderInterface`), `InstanceBrandAssetReader` (`BrandAssetReaderInterface`), `nowo_brand()`; requires
  `nowo-tech/brand-contracts` and `brand.defaults.name`; implies `files.enabled`, decided in this extension's prepend; a
  flag prepended later fails at compile time (IO-I10).
- **FR-002** `BrandBridgePass` re-aliases `nowo_ui_kit.appearance.provider` to the bridge only when `brand.enabled`,
  UiKit's appearance is enabled, `provider_is_default` is true and the alias targets UiKit's config provider or brand
  candidate (IS-I6, either pass order). A host provider always wins.
- **FR-003** With an empty store and the same name / eyebrow, the bridge's UiKit appearance equals
  `ConfigAppearanceProvider`'s (§3.4.3); without images it carries no logos object.
- **FR-004** Texts refuse control characters, bidi controls and line separators and respect the contract lengths; a
  refused stored text falls back to the configured texts; a translated default eyebrow with such characters is dropped
  for UiKit (IS-I4).
- **FR-005** Brand images are public image fields (no SVG); default assets are committed files under `public_dir`,
  validated at compile time (no traversal, no symbolic link out, image type, SHA-256), inline-safe; uploads are never
  inline-safe.
- **FR-006** The admin preview of a stored image is opt-in per field (`preview` form option), prints only the file route
  URL in an auto-escaped `<img src>`, never bytes, `data:` or base64 (IO Q3). A pre-save client preview is out of scope
  until UiKit ships a file-preview peer (`URL.createObjectURL` only).
- **FR-007** `SettingField::withGroup()` marks rows with `data-nowo-instance-settings-group`;
  `SettingsSectionSaveFilterInterface` lets a section rewrite saved values (used for lower-case colours and the
  preset rule).
- **FR-008** A store failure gives the configured defaults (logged); programming errors propagate.

## Success Criteria

- 100 % statement and element coverage; PHPStan, PHP-CS-Fixer, Rector, Igor clean.
- The admin pages of a host without `brand.enabled` are byte-identical to 1.4 (checked against `main` for the general,
  mailer, image and portability pages).
