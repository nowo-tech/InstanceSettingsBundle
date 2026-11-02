# Feature Specification: seed instance settings from the section fallbacks (DB-only mode)

**Feature Branch**: `feat/seed-from-fallbacks`  
**Created**: 2026-11-02  
**Status**: Active (1.2.0)  

**Package**: `nowo-tech/instance-settings-bundle`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md)  
**Code inventory**: [`../001-baseline/code-inventory.md`](../001-baseline/code-inventory.md) (section "Seed from fallbacks")

---

## Summary

Kits (SmsKit, MailKit…) used to fall back to bundle configuration (`%env(...)%` / YAML) while a field had no stored
value. 1.2.0 lets a section expose those fallbacks so that an instance copies them **once** into the store and then
runs with its settings in the database only ("DB-only mode"), dropping the env vars afterwards. Sections can also show
warnings on their admin page (e.g. "provider selected but its credentials are empty").

## User Scenarios

### US-1 — One-time bootstrap (P1)

**Given** a section implementing `SectionFallbackProviderInterface` and env-backed fallbacks, **When** the host runs
`nowo:instance-settings:seed-from-fallbacks` (or `FallbackSeeder::seed()` from an upgrade step / non-transactional
migration), **Then** every non-empty fallback whose field has no stored value is stored (secrets encrypted), audited
with origin `seed`, and the output lists `<section>.<field>` names only.

### US-2 — Idempotence (P1)

**Given** the seed already ran (or the admin set some values), **When** it runs again, **Then** nothing is written:
stored values are never overwritten.

### US-3 — Dry run and strict mode (P2)

`--dry-run` reports what would be seeded and writes nothing; `--strict` exits 1 when a fallback was refused (invalid,
unknown field, secret mismatch, duplicate).

### US-4 — Section notices (P2)

**Given** a section implementing `SettingsSectionNoticesInterface`, **When** its admin page renders, **Then** each
notice is shown as a UiKit warning alert above the form (labels only, never stored values).

### US-5 — Migration safety (P1)

**Given** a MySQL transactional migration whose DDL committed implicitly (DBAL nesting level > 0, no real
transaction), **When** the seeder is about to write, **Then** it throws a clear `LogicException` pointing to
non-transactional migrations instead of failing with "SAVEPOINT DOCTRINE_n does not exist" or corrupting state.

## Functional Requirements

- **FR-SEED-001** `SettingFallback` (plain / secret, validated field name, masked `__debugInfo()`) and the optional
  `SectionFallbackProviderInterface::getFallbacks()`.
- **FR-SEED-002** `FallbackSeeder::seed(?list $sections, bool $dryRun)`: only fields without a stored row; skips empty,
  unknown, secret-mismatch, invalid and duplicate fallbacks with a reason code; writes through
  `InstanceSettingsInterface::save()` (validation, encryption, log line, `SettingsChangedEvent` with
  `ORIGIN_SEED`) inside one repository transaction; public service; logs one summary line with names only.
- **FR-SEED-003** `SeedReport`: examined sections, seeded keys, skipped keys + reasons, seeded secret keys, dry-run flag.
- **FR-SEED-004** `nowo:instance-settings:seed-from-fallbacks` with `--section` (repeatable), `--dry-run`, `--strict`;
  prints names and reason codes only.
- **FR-SEED-005** `TransactionStateGuard`: before a non-dry-run write, a DBAL nesting level > 0 with a native PDO
  connection not in a transaction throws; unknown native connections pass (best effort).
- **FR-SEED-006** `SettingsSectionNoticesInterface::getNotices(SectionActionContext)`; `SettingsPageFactory::notices()`
  maps them to UiKit labels in the section domain; `section.html.twig` renders them as `flash_warning` alerts
  (`data-testid="instance-settings-notice"`).

## Success Criteria

- Unit + integration tests: idempotence, stored value kept, dry run writes nothing, secret never printed (command
  output, report, log), secret encrypted at rest, phantom transaction refused (mocked connection), notices rendered.
- 100% line coverage kept (CI gate); code inventory 100% of `src/`.

## Non-goals

- Reading environment variables directly: sections expose what their bundle configuration already holds.
- Removing the fallbacks: kits keep them for BC; hosts drop the env vars after seeding.

## Validation commands

```bash
make test-coverage && make coverage-check
make phpstan && make cs-check && make rector-dry && make validate-translations
```
