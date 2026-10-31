# Architecture

## Table of contents

- [Overview](#overview)
- [Request flow](#request-flow)
- [Components](#components)
- [Container wiring](#container-wiring)
- [Worker safety](#worker-safety)
- [Extension points](#extension-points)

## Overview

```
Sections (host / kits) ──tag──► SettingsSectionRegistry ◄── SettingsController ◄── HTTP (route loader, prefix)
                                        │                          │   AccessGuard ◄── InstanceSettingsAccessListener
                                        ▼                          ▼
App code / Twig ──► InstanceSettings (memo per main request) ──► SettingValueRepository ──► instance_setting_value
                        │   ValueNormalizer (types + constraints)        (Doctrine ORM, #[Encrypted] secret column
                        └── SettingsChangedEvent                          via DoctrineEncryptBundle)

ConfigPortability ──► export: settings + sealed secrets (SecretSealer) + PortabilityProviders ──► JSON envelope
                  ◄── import: JsonUploadReader (cap) → validate all → one transaction
```

## Request flow

1. A request under `web_ui.path_prefix` (or to a bundle controller anywhere) passes the host firewall, then
   `InstanceSettingsAccessListener` (kernel.request, priority 7) asks `AccessGuard` → the access checker.
2. `SettingsController` resolves the section, re-checks its area, builds `SettingsSectionType` from the section fields
   (plain values from `InstanceSettings::getSection()`, secrets only as "stored" flags) and handles the POST (CSRF).
3. `InstanceSettings::save()` normalizes and validates every value, upserts the changed rows with one flush, clears its
   memo, logs field names and dispatches `SettingsChangedEvent`.
4. The page is a UiKit settings scaffold built by `SettingsPageFactory`.

## Components

| Component | Role |
| --- | --- |
| `Section\SettingsSectionInterface`, `AbstractSettingsSection`, `SettingField`, `SettingType` | Section / field contract |
| `Section\SettingsSectionRegistry` | Ordered, validated section map (built once per worker from tagged stateless services) |
| `Settings\InstanceSettings` (`InstanceSettingsInterface`) | Typed reads, saves, request-bound memo, `kernel.reset` |
| `Settings\ValueNormalizer` | Type coercion, built-in and field constraints |
| `Entity\SettingValue`, `Repository\DoctrineSettingValueRepository`, `Doctrine\TablePrefixListener` | Storage (one table, prefix, refreshed reads, EM recovery) |
| `Secret\DoctrineEncryptSecretSealer` | Sealing secrets for exports with the DoctrineEncrypt key |
| `Portability\*` | Envelope, providers, export / import service, size-capped reader, reason-coded exception |
| `Command\Export/ImportSettingsCommand` | CLI |
| `Controller\SettingsController`, `Form\*`, `Ui\*`, `Routing\InstanceSettingsRouteLoader` | Admin UI |
| `Security\*`, `EventListener\InstanceSettingsAccessListener` | Access (roles, area roles, custom checker, demo switch) |
| `Twig\InstanceSettingsTwigExtension` | `instance_setting()` |
| `DependencyInjection\*` | Config tree, extension, fail-closed passes, Twig paths, section tag normalization |

## Container wiring

Services are registered explicitly in `NowoInstanceSettingsExtension` (no resource scanning). Sections and portability
providers are collected with tagged iterators (`nowo_instance_settings.section`, `…portability_provider`) and
autoconfigured from their interfaces. The controller is **not** autoconfigured (REQ-SF-004 rule 7); its routes come from
the `nowo_instance_settings` loader. Compiler passes: `TwigPathsPass`, `CsrfProtectionPass`, `UnauthenticatedAccessPass`,
`SecretEncryptionPass`, `SettingsSectionPass`.

## Worker safety

See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md): one memo bound to the main request, the immutable section
map, and the route loader's load-once flag; everything else is stateless.

## Extension points

- Sections (`SettingsSectionInterface`, `SettingsSectionActionsInterface`, `#[AsSettingsSection]`).
- `SettingsChangedEvent` listeners.
- Portability providers (`PortabilityProviderInterface`).
- Access checker (`security.access_checker`), sealer (`SecretSealerInterface` alias), repository
  (`SettingValueRepositoryInterface` alias).
- Templates (`web_ui.templates`, UiKit theme, overrides) — [TEMPLATES.md](TEMPLATES.md).
