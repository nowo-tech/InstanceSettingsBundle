# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/instance-settings-bundle`  
**Last audited**: 2026-11-02

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **67**.

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
| `src/Section/SectionActionContext.php` | Sections | FR-SEC-003 |
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
| `src/Routing/InstanceSettingsRoutes.php` | Admin UI | FR-UI-001, FR-SEC-003 |
| `src/Controller/SettingsController.php` | Admin UI | FR-UI-002, FR-SEED-006 |
| `src/Form/SettingsSectionType.php` | Admin UI | FR-UI-003 |
| `src/Form/PortabilityExportType.php` | Admin UI | FR-UI-003 |
| `src/Form/PortabilityImportType.php` | Admin UI | FR-UI-003 |
| `src/Ui/SettingsPageFactory.php` | Admin UI | FR-UI-004, FR-SEED-006 |
| `src/Ui/SettingsPages.php` | Admin UI | FR-UI-004 |
| `src/Resources/views/settings/section.html.twig` | Admin UI | FR-UI-004, FR-SEED-006 |
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

## Seed from fallbacks (spec 002)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Section/SettingFallback.php` | [002](../002-seed-from-fallbacks/spec.md) | FR-SEED-001 |
| `src/Section/FallbackValue.php` | [002](../002-seed-from-fallbacks/spec.md) | FR-SEED-001 |
| `src/Section/SectionFallbackProviderInterface.php` | [002](../002-seed-from-fallbacks/spec.md) | FR-SEED-001 |
| `src/Section/SettingsSectionNoticesInterface.php` | [002](../002-seed-from-fallbacks/spec.md) | FR-SEED-006 |
| `src/Seed/FallbackSeeder.php` | [002](../002-seed-from-fallbacks/spec.md) | FR-SEED-002 |
| `src/Seed/SeedReport.php` | [002](../002-seed-from-fallbacks/spec.md) | FR-SEED-003 |
| `src/Seed/TransactionStateGuard.php` | [002](../002-seed-from-fallbacks/spec.md) | FR-SEED-005 |
| `src/Command/SeedFromFallbacksCommand.php` | [002](../002-seed-from-fallbacks/spec.md) | FR-SEED-004 |

## Provider-dependent fields (spec 003)

Files changed by [003](../003-provider-dependent-fields/spec.md) (no new file; mapped above as well):

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Section/SettingField.php` | [003](../003-provider-dependent-fields/spec.md) | FR-VIS-001, FR-VIS-002 |
| `src/Section/SettingsSectionRegistry.php` | [003](../003-provider-dependent-fields/spec.md) | FR-VIS-003 |
| `src/Form/SettingsSectionType.php` | [003](../003-provider-dependent-fields/spec.md) | FR-VIS-004 |
| `src/Controller/SettingsController.php` | [003](../003-provider-dependent-fields/spec.md) | FR-VIS-005 |
| `src/Ui/SettingsPageFactory.php` | [003](../003-provider-dependent-fields/spec.md) | FR-VIS-006 |
| `src/Settings/InstanceSettings.php` | [003](../003-provider-dependent-fields/spec.md) | FR-VIS-008 |
| `src/Settings/ValueNormalizer.php` | [003](../003-provider-dependent-fields/spec.md) | FR-VIS-008 |

## Image fields (spec 004, contract 220 S2)

New files of [004](../004-image-fields/spec.md):

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Section/ImageSpec.php` | [004](../004-image-fields/spec.md) | FR-001 |
| `src/Entity/SettingFile.php` | [004](../004-image-fields/spec.md) | FR-004 |
| `src/File/DoctrineSettingFileStore.php` | [004](../004-image-fields/spec.md) | FR-004, FR-008 |
| `src/File/EncodedImage.php` | [004](../004-image-fields/spec.md) | FR-002 |
| `src/File/GdSupport.php` | [004](../004-image-fields/spec.md) | FR-002 |
| `src/File/ImageProcessor.php` | [004](../004-image-fields/spec.md) | FR-002 |
| `src/File/ImageRefusedException.php` | [004](../004-image-fields/spec.md) | FR-002 |
| `src/File/ImageUpload.php` | [004](../004-image-fields/spec.md) | FR-002, FR-006 |
| `src/File/ProcessedImage.php` | [004](../004-image-fields/spec.md) | FR-002 |
| `src/File/SettingFileController.php` | [004](../004-image-fields/spec.md) | FR-005 |
| `src/File/SettingFileRecord.php` | [004](../004-image-fields/spec.md) | FR-004 |
| `src/File/SettingFileStoreInterface.php` | [004](../004-image-fields/spec.md) | FR-004 |
| `src/File/SettingFileUrls.php` | [004](../004-image-fields/spec.md) | FR-003, FR-005 |
| `src/File/StoredSettingFile.php` | [004](../004-image-fields/spec.md) | FR-003 |
| `src/Form/SettingImageType.php` | [004](../004-image-fields/spec.md) | FR-006 |
| `src/Command/PurgeFilesCommand.php` | [004](../004-image-fields/spec.md) | FR-008 |

Files changed by [004](../004-image-fields/spec.md) (mapped above as well):

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Section/SettingType.php` | [004](../004-image-fields/spec.md) | FR-001 |
| `src/Section/SettingField.php` | [004](../004-image-fields/spec.md) | FR-001 |
| `src/Section/SettingsSectionRegistry.php` | [004](../004-image-fields/spec.md) | FR-002 |
| `src/Settings/InstanceSettings.php` | [004](../004-image-fields/spec.md) | FR-003, FR-004, FR-009 |
| `src/Settings/InstanceSettingsInterface.php` | [004](../004-image-fields/spec.md) | FR-003 |
| `src/Settings/ValueNormalizer.php` | [004](../004-image-fields/spec.md) | FR-001 |
| `src/Event/SettingsChangedEvent.php` | [004](../004-image-fields/spec.md) | FR-009 |
| `src/Portability/ConfigPortability.php` | [004](../004-image-fields/spec.md) | FR-007 |
| `src/Portability/PortabilityEnvelope.php` | [004](../004-image-fields/spec.md) | FR-007 |
| `src/Form/SettingsSectionType.php` | [004](../004-image-fields/spec.md) | FR-006 |
| `src/Form/PortabilityExportType.php` | [004](../004-image-fields/spec.md) | FR-007 |
| `src/Controller/SettingsController.php` | [004](../004-image-fields/spec.md) | FR-006, FR-007 |
| `src/Command/ExportSettingsCommand.php` | [004](../004-image-fields/spec.md) | FR-007 |
| `src/Routing/InstanceSettingsRouteLoader.php` | [004](../004-image-fields/spec.md) | FR-005 |
| `src/Routing/InstanceSettingsRoutes.php` | [004](../004-image-fields/spec.md) | FR-005 |
| `src/Twig/InstanceSettingsTwigExtension.php` | [004](../004-image-fields/spec.md) | FR-003 |
| `src/DependencyInjection/Configuration.php` | [004](../004-image-fields/spec.md) | FR-005, FR-007, FR-008 |
| `src/DependencyInjection/NowoInstanceSettingsExtension.php` | [004](../004-image-fields/spec.md) | FR-004, FR-005, FR-008 |

## Totals

- Mapped files: **83/83** (100%).
- Re-audit: run the audit command and compare with this table on every PR that adds, moves or removes a file under `src/`.
