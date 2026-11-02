# Feature Specification: provider-dependent fields in settings sections (`visible_when`)

**Feature Branch**: `feat/1.3.0`  
**Created**: 2026-11-02  
**Status**: Active (1.3.0)  

**Package**: `nowo-tech/instance-settings-bundle`  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md)  
**Code inventory**: [`../001-baseline/code-inventory.md`](../001-baseline/code-inventory.md) (section "Provider-dependent fields")  
**Depends on**: `nowo-tech/ui-kit-bundle` 2.23 (`Form\VisibleWhen`, Stimulus peer `visible-when`)

---

## Summary

Kits with a provider choice (SmsKit: twilio / sms_bridge / log / null; MailKit: Resend on / off) show every
credential field of every provider at once. 1.3.0 lets a field declare that it is only relevant while another field of
the same section holds some values. The admin form hides the other providers' rows **without** losing their values:
switching provider, saving and switching back shows the stored values again.

## User Scenarios

### US-1 — Only the selected provider's fields (P1)

**Given** a section field declared with `withVisibleWhen('provider', ['twilio'])`, **When** the admin selects another
provider in the form, **Then** the row is hidden (`hidden`, `inert`, `aria-hidden`; UiKit `visible-when` peer) and its
input keeps its value; selecting `twilio` again shows it. Without JavaScript every row is visible.

### US-2 — Values of the other providers survive a save (P1)

**Given** stored Twilio credentials, **When** the admin switches to SMS Bridge and saves, **Then** the Twilio rows are
neither validated nor written (even submitted empty) and keep their stored values; switching back shows them. Checking
"clear" on a hidden Twilio token deletes it (a typed value is still ignored).

### US-3 — Validation follows the submitted provider (P1)

**Given** a required field visible only for `smtp`, **When** the form is submitted with provider `api`, **Then** the
field is not required, not validated and shows no error; with provider `smtp` it is required and "empty = delete"
applies to it.

### US-4 — Invalid definitions fail early (P2)

**Given** a `visible_when` naming a field that is not in the section, a secret, a provider-dependent field, or a value
that is not one of the controlling choice / bool values, **When** the section definitions load, **Then** a
`LogicException` names the section, the field and the problem.

## Functional Requirements

- **FR-VIS-001** `SettingField::$visibleWhen` (`{field: string, in: list<string>}|null`), constructor argument
  `visibleWhen` and `withVisibleWhen(string $field, iterable $in)`; shape validated in the constructor (exactly `field`
  + `in`, field name pattern, not the field itself, at least one string / int / bool value; values normalized to
  strings, `true` → `'1'`, `false` → `''`, deduplicated). Kept by `notPortable()` and `withFormOptions()`.
- **FR-VIS-002** `SettingField::isVisibleFor(array $values)` and `SettingField::visibilityValue(mixed)`.
- **FR-VIS-003** `SettingsSectionRegistry` validates every `visible_when` when the definitions load: controlling field
  exists in the section, is not secret, is not provider-dependent; choice / bool controllers accept only their values.
- **FR-VIS-004** `SettingsSectionType` renders the option as `row_attr` with `Nowo\UiKitBundle\Form\VisibleWhen`
  (controlling input name `<form>[<field>]`), merged with a `row_attr` from `withFormOptions()`, for plain and secret
  fields. A `PRE_SUBMIT` listener replaces the submitted value of every field hidden for the submitted controlling
  value with its current view value; a hidden secret keeps only an explicit `clear`. The controlling value is
  normalized as the form type does (`submittedValue()`: `trim`, then the view and model transformers in reverse), so
  the listener and the controller decide visibility on the same value.
- **FR-VIS-005** `SettingsController` saves only the fields visible for the submitted values (a hidden field is never
  validated, written or deleted), except a hidden secret with `clear` checked, whose stored row is deleted (audit
  SK-L1: remove an inactive provider's leaked credential; never writes a value).
- **FR-VIS-008** `InstanceSettings::save()` / `ValueNormalizer::normalize($field, $value, $enforceRequired)`: `required`
  is not enforced on a provider-dependent field hidden for the saved + effective values. Two passes: every value is
  normalized first, then visibility is decided on the normalized values merged over the stored ones (audit N1).
- **FR-VIS-006** `SettingsPageFactory::hasVisibleWhenRows()`; the section page root gets
  `data-controller="visible-when"` only when a row carries the attributes (pages without `visible_when` unchanged).
- **FR-VIS-007** Unchanged: the seed, `InstanceSettings::save()` (API), imports and exports store every given field.

## Success Criteria

- Unit tests (option shape, registry validation, visibility, form attributes, hidden values kept on submit) and a
  functional test (switch, save, hidden kept, visible empty deletes, required only when visible, secrets kept).
- Demo Playwright e2e: switch provider, save, switch back, values still there; axe without violations in both states.
- 100% coverage kept; code inventory 100% of `src/`; translation parity unchanged (no new bundle keys).

## Non-goals

- Chained conditions (a dependent field controlling another one) and expressions other than "value in list".
- Disabling inputs (Beacon's `field_visibility` mode): hidden fields are still submitted.

## Validation commands

```bash
make test-coverage && make coverage-check
make phpstan && make cs-check && make rector-dry && make validate-translations
make test-e2e
```
