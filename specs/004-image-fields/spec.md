# Feature Specification: image setting fields (spec 220, slice S2)

**Feature Branch**: `feat/s2-image-field`  
**Created**: 2026-11-08  
**Status**: Implemented, awaiting the immediate security audit (target 1.4.0)  

**Package**: `nowo-tech/instance-settings-bundle`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md)  
**Contract**: workspace spec 220 "Brand fully configurable from the bundles", §5 (image field type, security design)
and the S2 row of §8; owner decisions of 2026-11-08 (database blobs behind a store interface, SVG refused, re-encode
with GD, content-addressed immutable URLs outside the admin prefix, envelope v2, 30-day orphan retention, hashes only
in logs and events).

---

## Summary

Brand assets (logos, marks, icons) and other images become instance settings. An image field accepts PNG, JPEG and
still WebP uploads, decided from the content, re-encodes them with GD, stores them in the database bound to the field,
serves them from an immutable hashed URL with hardened headers, exports / imports them in envelope version 2 and purges
replaced ones after a retention. Hosts that declare no image field see no change except one new, empty table.

## User Scenarios

### US-1 — Upload a logo (P1)

**Given** a section with `SettingField::image('logo', public: true)`, **When** a user of the section's area submits the
section form with a PNG / JPEG / WebP file and a valid CSRF token, **Then** the image is checked, re-encoded and stored,
`getFile('brand.logo')` returns its hash, type, dimensions and URL, and anonymous visitors can load that URL.

### US-2 — Hostile files are refused (P1)

**Given** an SVG, HTML, PDF, GIF or BMP file (whatever its name and declared type), a polyglot, a decompression bomb,
an animated WebP, an oversized or out-of-bounds image, **When** it is uploaded or imported, **Then** it is refused with
a reason, nothing is stored, and no decoding happens for header-level refusals. Appended payloads and EXIF metadata of
an accepted image never reach storage.

### US-3 — Replace and remove (P1)

**When** the logo is replaced or removed, **Then** the old image is kept (still served at its old URL) for
`files.orphan_retention` days and deleted by `nowo:instance-settings:purge-files` afterwards.

### US-4 — Private images (P2)

**Given** a field without `public`, **Then** only users of the section's area load it; everyone else gets the same 404
as for an unknown hash.

### US-5 — Move the configuration between instances (P2)

**When** an export with files is imported elsewhere, **Then** every file is fully re-validated (strict base64, caps,
declared hash and type, the upload pipeline) and applied all-or-nothing; any tampering refuses the whole file.

### US-6 — Audit (P2)

**When** an image changes, **Then** the save log line and `SettingsChangedEvent::$fileChanges` record hashes (and the
log type, dimensions and size), never content.

## Requirements

- FR-001 `SettingType::Image`, `SettingField::image()`, `ImageSpec`; references `isf:<sha256>` only.
- FR-002 Content sniffing (magic bytes + `getimagesizefromstring()`), header limits before decoding (16 MP, field
  limits, memory budget), GD re-encode, PNG variants; fail closed without GD.
- FR-003 `InstanceSettingsInterface::getFile()`, `instance_setting_file()`; `get()` never returns a reference.
- FR-004 Database store behind `SettingFileStoreInterface`, content-addressed per field, orphan marking in the save
  transaction.
- FR-005 File route outside the admin prefix, only when an image field exists; headers of contract §5.6; same 404 for
  every miss; area check for private fields; old hashes served until purged.
- FR-006 Uploads only through the section form (CSRF, area role).
- FR-007 Envelope v2 `files`, version 1 kept without image fields, full re-validation on import.
- FR-008 `purge-files` with retention and dry run; fields removed from code orphan their images.
- FR-009 Audit fields (log, event).

## Success Criteria

- 100 % line coverage; the S2 test plan of the contract (sniffing table, polyglots, bomb, EXIF, headers, 304, 404
  parity, import v1 / v2 and tampering, purge timing, worker reset) is covered.
- `docs/SECURITY.md` lists every threat with its mitigation and tests.

## Deviations from the contract

See the pull request description (blobs bound to their field instead of one global row per hash, integer ids instead
of UUIDs, `variant` column instead of `variant_of`, GD checked when the section definitions load instead of at
container compile time, 304 path costs one metadata query instead of a memo lookup).
