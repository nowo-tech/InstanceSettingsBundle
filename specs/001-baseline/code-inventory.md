# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/instance-settings-bundle`  
**Last audited**: 2026-10-31

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **57**.

## Bundle and dependency injection

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/NowoInstanceSettingsBundle.php` | Bundle and dependency injection | FR-BUNDLE-001 |
| `src/DependencyInjection/Configuration.php` | Bundle and dependency injection | FR-CFG-001 |
| `src/DependencyInjection/NowoInstanceSettingsExtension.php` | Bundle and dependency injection | FR-CFG-002, FR-CFG-003, FR-CFG-004 |
| `src/DependencyInjection/Compiler/CsrfProtectionPass.php` | Bundle and dependency injection | FR-CFG-005 |
| `src/DependencyInjection/Compiler/SecretEncryptionPass.php` | Bundle and dependency injection | FR-CFG-005 |
| `src/DependencyInjection/Compiler/UnauthenticatedAccessPass.php` | Bundle and dependency injection | FR-CFG-005 |
| `src/DependencyInjection/Compiler/SettingsSectionPass.php` | Bundle and dependency injection | FR-CFG-003 |
| `src/DependencyInjection/Compiler/TwigPathsPass.php` | Bundle and dependency injection | FR-TWIG-001 |
| `src/Attribute/AsSettingsSection.php` | Bundle and dependency injection | FR-SEC-003, FR-CFG-003 |

## Sections

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Section/SettingsSectionInterface.php` | Sections | FR-SEC-002, FR-SEC-003 |
| `src/Section/AbstractSettingsSection.php` | Sections | FR-SEC-003 |
| `src/Section/SettingsSectionActionsInterface.php` | Sections | FR-SEC-003, FR-UI-004 |
| `src/Section/SettingField.php` | Sections | FR-SEC-001 |
| `src/Section/SettingType.php` | Sections | FR-SEC-001 |
| `src/Section/SettingsSectionRegistry.php` | Sections | FR-SEC-002 |

## Store and persistence

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Settings/InstanceSettingsInterface.php` | Store and persistence | FR-STORE-001, FR-STORE-003 |
| `src/Settings/InstanceSettings.php` | Store and persistence | FR-STORE-001, FR-STORE-002, FR-STORE-003 |
| `src/Settings/ValueNormalizer.php` | Store and persistence | FR-STORE-004 |
| `src/Event/SettingsChangedEvent.php` | Store and persistence | FR-STORE-003 |
| `src/Exception/InvalidSettingValueException.php` | Store and persistence | FR-STORE-003 |
| `src/Exception/UnknownSettingException.php` | Store and persistence | FR-SEC-002 |
| `src/Entity/SettingValue.php` | Store and persistence | FR-ORM-001 |
| `src/Repository/SettingValueRepositoryInterface.php` | Store and persistence | FR-ORM-001 |
| `src/Repository/DoctrineSettingValueRepository.php` | Store and persistence | FR-ORM-001 |
| `src/Doctrine/TablePrefixListener.php` | Store and persistence | FR-ORM-002 |
| `src/Twig/InstanceSettingsTwigExtension.php` | Store and persistence | FR-TWIG-002 |

## Admin UI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Routing/InstanceSettingsRouteLoader.php` | Admin UI | FR-UI-001 |
| `src/Controller/SettingsController.php` | Admin UI | FR-UI-002 |
| `src/Form/SettingsSectionType.php` | Admin UI | FR-UI-003 |
| `src/Form/PortabilityExportType.php` | Admin UI | FR-UI-003 |
| `src/Form/PortabilityImportType.php` | Admin UI | FR-UI-003 |
| `src/Ui/SettingsPageFactory.php` | Admin UI | FR-UI-004 |
| `src/Ui/SettingsPages.php` | Admin UI | FR-UI-004 |
| `src/Resources/views/settings/section.html.twig` | Admin UI | FR-UI-004 |
| `src/Resources/views/settings/portability.html.twig` | Admin UI | FR-UI-004 |
| `src/Security/InstanceSettingsAccessCheckerInterface.php` | Admin UI | FR-ACC-001 |
| `src/Security/ConfigurableInstanceSettingsAccessChecker.php` | Admin UI | FR-ACC-001 |
| `src/Security/AllowAllInstanceSettingsAccessChecker.php` | Admin UI | FR-ACC-001 |
| `src/Security/AccessGuard.php` | Admin UI | FR-ACC-001 |
| `src/EventListener/InstanceSettingsAccessListener.php` | Admin UI | FR-ACC-001 |
| `src/Resources/translations/NowoInstanceSettingsBundle.de.yaml` | Admin UI | FR-UI-004 |
| `src/Resources/translations/NowoInstanceSettingsBundle.en.yaml` | Admin UI | FR-UI-004 |
| `src/Resources/translations/NowoInstanceSettingsBundle.es.yaml` | Admin UI | FR-UI-004 |
| `src/Resources/translations/NowoInstanceSettingsBundle.fr.yaml` | Admin UI | FR-UI-004 |
| `src/Resources/translations/NowoInstanceSettingsBundle.it.yaml` | Admin UI | FR-UI-004 |
| `src/Resources/translations/NowoInstanceSettingsBundle.nl.yaml` | Admin UI | FR-UI-004 |
| `src/Resources/translations/NowoInstanceSettingsBundle.pt.yaml` | Admin UI | FR-UI-004 |

## Portability

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Portability/PortabilityEnvelope.php` | Portability | FR-PORT-001 |
| `src/Portability/PortabilityProviderInterface.php` | Portability | FR-PORT-003 |
| `src/Portability/ConfigPortability.php` | Portability | FR-PORT-002, FR-PORT-003 |
| `src/Portability/ImportResult.php` | Portability | FR-PORT-003 |
| `src/Portability/PortabilityException.php` | Portability | FR-PORT-005 |
| `src/Portability/JsonUploadReader.php` | Portability | FR-PORT-005 |
| `src/Secret/SecretSealerInterface.php` | Portability | FR-PORT-004 |
| `src/Secret/DoctrineEncryptSecretSealer.php` | Portability | FR-PORT-004 |
| `src/Command/ExportSettingsCommand.php` | Portability | FR-PORT-006 |
| `src/Command/ImportSettingsCommand.php` | Portability | FR-PORT-006 |

## Totals

- Mapped files: **57/57** (100%).
- Re-audit: run the audit command and compare with this table on every PR that adds, moves or removes a file under `src/`.
