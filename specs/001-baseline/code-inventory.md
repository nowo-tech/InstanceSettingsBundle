# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/instance-settings-bundle`  
**Last audited**: 2026-11-17

Scope: every file under `src/`. Audit command: `git ls-files src | wc -l` → **205**.

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
| `src/FileEntity/SettingFile.php` | [004](../004-image-fields/spec.md) | FR-004 |
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
| `src/Runtime/InstanceSettingsRuntimeSource.php` | [USAGE: Runtime settings (1.20)](../../docs/USAGE.md#runtime-settings-120) | FR-RS-001 |
| `src/Runtime/InstanceSettingsRuntimeWriter.php` | [USAGE: Runtime settings (1.20)](../../docs/USAGE.md#runtime-settings-120) | FR-RS-002 |
| `src/Runtime/RuntimeSettingTables.php` | [HIGH-LOAD: Runtime settings (1.20)](../../docs/HIGH-LOAD.md#runtime-settings-120) | FR-RS-003 |
| `src/Runtime/RuntimeSettingAdminGuard.php` | [USAGE: Runtime settings (1.20)](../../docs/USAGE.md#runtime-settings-120) | FR-RS-004 |
| `src/RuntimeEntity/RuntimeSettingVersion.php` | [HIGH-LOAD: Runtime settings (1.20)](../../docs/HIGH-LOAD.md#runtime-settings-120) | FR-RS-003 |
| `src/RuntimeEntity/RuntimeSettingPrevious.php` | [USAGE: Runtime settings (1.20)](../../docs/USAGE.md#runtime-settings-120) | FR-RS-002 |
| `src/DependencyInjection/Compiler/RuntimeSettingPortsPass.php` | [UPGRADING: Unreleased](../../docs/UPGRADING.md#unreleased) | FR-RS-005 |

## Sources added after the last baseline review (sweep 2026-11-16)

These 115 `src/` files were added by releases after the last review. Each row maps the file to the requirement already used for its neighbours in the same directory; the description comes from the class docblock. With them, every file under `src/` is listed in this inventory.

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Attribute/AsSettingsTester.php` | Registers a SettingsSectionTesterInterface service as the tester of a settings section, or of one field group of it (... | FR-SEC-003 |
| `src/Brand/AppearanceSection.php` | The `appearance` settings section of the brand bridge (spec 220 §3.1, §6): the preset per mode, seven colours per mod... | FR-BRAND-001 |
| `src/Brand/BrandDefaults.php` | `nowo_instance_settings.brand.defaults` and `extra_images`, resolved when the container compiles: the texts used whil... | FR-BRAND-001 |
| `src/Brand/BrandErrorIllustrationProvider.php` | UiKit error illustration bridge (1.13): the illustration of an error page from the uploaded brand extra images. | FR-BRAND-001 |
| `src/Brand/BrandFallbackProviderInterface.php` | Host seam of the brand bridge (spec 220 §7.1, `nowo_instance_settings.brand.fallback_provider`): the values the `bran... | FR-BRAND-001 |
| `src/Brand/BrandPalettes.php` | Resolves the stored values of the `appearance` section against UiKit's presets and defaults (spec 220 §3.1). | FR-BRAND-001 |
| `src/Brand/BrandSection.php` | The `brand` settings section of the brand bridge (spec 220 §6, `brand.enabled: true`): identity texts and the brand i... | FR-BRAND-001 |
| `src/Brand/BrandTwigExtension.php` | `nowo_brand()` (1.5, `brand.enabled: true`): the contract Brand for host templates (name, colours, assets, version). | FR-BRAND-001 |
| `src/Brand/DefaultAsset.php` | A config default brand asset (spec 220 §4.2, §7.2): a committed file under `brand.public_dir`, served by the web serv... | FR-BRAND-001 |
| `src/Brand/InstanceBrandAssetReader.php` | Reads the bytes of a brand asset for consumers that embed it (CID e-mail attachments, PDF images; spec 220 §2.3, §7.5... | FR-BRAND-001 |
| `src/Brand/InstanceBrandProvider.php` | The brand bridge (spec 220 §2.2, `nowo_instance_settings.brand.enabled: true`): implements the `nowo-tech/brand-contr... | FR-BRAND-001 |
| `src/Brand/TranslatableBrandText.php` | A default brand text given as a translation key (`brand.defaults.eyebrow: {key, domain}`): translated in the viewer's... | FR-BRAND-001 |
| `src/Command/CheckExpiringSecretsCommand.php` | `nowo:instance-settings:check-expiring-secrets` (1.17): announces the expiring secrets that reached one of `expiring_... | FR-PORT-006 |
| `src/Command/SeedCommand.php` | `nowo:instance-settings:seed [file] [--section=…] [--dry-run]` (1.7, IS-3): loads the host seed file (default `seed.f... | FR-PORT-006 |
| `src/DependencyInjection/Compiler/BrandBridgePass.php` | Bundle and dependency injection: Brand bridge (1.5, spec 220 §2.2): with `nowo_instance_settings.brand.enabled: true`, UiKit's appearance enabled, no... | FR-BRAND-001 |
| `src/DependencyInjection/Compiler/EntityManagerMappingPass.php` | Bundle and dependency injection: Keeps the bundle entities on `doctrine.entity_manager` only (1.11, architecture bar rule 6). | FR-CFG-005 |
| `src/DependencyInjection/Compiler/FileStoreMiddlewarePass.php` | Bundle and dependency injection: Attaches the filesystem store's DBAL middleware (FileStoreTransactionMiddleware) only to the connection of the bundle... | FR-CFG-005 |
| `src/DependencyInjection/Compiler/FileSupportPass.php` | Bundle and dependency injection: Fails closed at container compile time when `files.enabled: true` and GD lacks PNG, JPEG or WebP support (spec 220 §5... | FR-CFG-005 |
| `src/DependencyInjection/Compiler/SettingsTesterPass.php` | Bundle and dependency injection: Collects the section testers (1.18, tag `nowo_instance_settings.tester`, set by AsSettingsTester) into SectionTesterR... | FR-CFG-005 |
| `src/Doctrine/ExcludedEntityNamespaceDriver.php` | Metadata driver that maps nothing: registered for the bundle entity namespaces on every entity manager other than `do... | FR-ORM-002 |
| `src/Doctrine/FileStoreTransactionConnection.php` | Driver connection of FileStoreTransactionMiddleware: reports the real transaction and its savepoints to the observers... | FR-ORM-002 |
| `src/Doctrine/FileStoreTransactionDriver.php` | Driver of FileStoreTransactionMiddleware: wraps each connection in a FileStoreTransactionConnection. | FR-ORM-002 |
| `src/Doctrine/FileStoreTransactionMiddleware.php` | DBAL middleware of the bundle's connection: tells its observers when a **real** database transaction starts, commits... | FR-ORM-002 |
| `src/Doctrine/KeyCollationListener.php` | Exact-match collation of the key columns (`section`, `name`) of the bundle tables on MySQL / MariaDB (1.19, Beacon sp... | FR-ORM-002 |
| `src/Doctrine/SequenceId.php` | The id of a row the bundle inserts with plain DBAL (1.19): null when the database generates it (IDENTITY, AUTO_INCREM... | FR-ORM-002 |
| `src/Doctrine/SettingsEntityManager.php` | The bundle's own entity manager (1.11, architecture bar rule 6). | FR-ORM-002 |
| `src/Doctrine/TransactionObserverInterface.php` | Told by FileStoreTransactionMiddleware about the **real** database transactions of the bundle's connection and their... | FR-ORM-002 |
| `src/Embed/SectionEmbedOptions.php` | How a host page embeds settings sections (1.6, IS-1): which sections its nav shows, the host route of each section (t... | FR-EMBED-001 |
| `src/Embed/SettingsSectionEmbedder.php` | Shows and saves one settings section: the bundle's own section page and, since 1.6 (IS-1), the same page embedded in... | FR-EMBED-001 |
| `src/Event/AfterCommitEventDispatcher.php` | Dispatches the bundle's events (SettingsChangedEvent) only once the write is committed (1.10, DDD P2). | FR-STORE-003 |
| `src/Event/DeferredWork.php` | Work held by AfterCommitEventDispatcher::afterCommit() like an event (1.19): run once the write is committed, dropped... | FR-STORE-003 |
| `src/Event/SecretExpiryThresholdReachedEvent.php` | An expiring secret reached one of the `expiring_secrets.thresholds` (1.17), dispatched (PSR-14) by SecretExpiryChecke... | FR-STORE-003 |
| `src/Event/SecretRevealedEvent.php` | Audit record of one reveal of a stored secret (1.15, SecretRevealer::reveal()): dispatched through the PSR-14 dispatc... | FR-STORE-003 |
| `src/Event/SettingsPendingEventsInterface.php` | Committed-but-not-yet-dispatched SettingsChangedEvents (1.11.1, public API, SemVer). | FR-STORE-003 |
| `src/Event/SettingsTestedEvent.php` | Record of one section test (1.18, SectionTestRunner): dispatched through the PSR-14 dispatcher after the test ran and... | FR-STORE-003 |
| `src/EventListener/WorkerBoundaryResetListener.php` | Clears the settings memo and the bundle's private entity manager at every unit-of-work boundary (REQ-RUNTIME-002). | FR-ACC-001 |
| `src/Exception/SettingsConflictException.php` | Store and persistence: A section form was saved over a change another editor saved since the form was opened (1.19, Beacon spec 239, Snapsho... | FR-STORE-003 |
| `src/Expiry/DoctrineSecretExpiryStore.php` | Database store of the expiry dates (table `instance_setting_secret_expiry`, 1.17). | FR-EXPIRY-001 |
| `src/Expiry/ExpiringSecret.php` | One expiring secret within a window (1.17), the value object of ExpiringSecretSourceInterface: where it is (section,... | FR-EXPIRY-001 |
| `src/Expiry/ExpiringSecretSourceInterface.php` | Read port of the expiring secrets (1.17): which declared expiring secret fields expire within a window. | FR-EXPIRY-001 |
| `src/Expiry/ExpiringSecrets.php` | Default ExpiringSecretSourceInterface (1.17) over the registry, the expiry store and the settings store, plus what th... | FR-EXPIRY-001 |
| `src/Expiry/ExpiryDate.php` | Calendar dates of the expiring secrets (1.17): a date has no time and no zone. | FR-EXPIRY-001 |
| `src/Expiry/LockingSecretExpiryStoreInterface.php` | Optional capability of a SecretExpiryStoreInterface (1.19.1, audit QQ-D2-L-1): the dates of a section read with a loc... | FR-EXPIRY-001 |
| `src/Expiry/SecretExpiryCheckReport.php` | Outcome of one SecretExpiryChecker::check() run (1.17): the thresholds announced (or due, in a dry run) and the `sect... | FR-EXPIRY-001 |
| `src/Expiry/SecretExpiryChecker.php` | Announces the expiring secrets that reached a threshold (1.17): one SecretExpiryThresholdReachedEvent per threshold p... | FR-EXPIRY-001 |
| `src/Expiry/SecretExpiryRecord.php` | One stored expiry date (1.17), the value object of SecretExpiryStoreInterface: section, field name, date and the last... | FR-EXPIRY-001 |
| `src/Expiry/SecretExpiryStoreInterface.php` | Storage of the expiry dates of expiring secrets (1.17): the bundle's own store, framework-free (dates and SecretExpir... | FR-EXPIRY-001 |
| `src/ExpiryEntity/SecretExpiry.php` | Expiry date of one expiring secret field (1.17), stored next to its secret: one row per `(section, name)`.  Mapped on... | FR-EXPIRY-001 |
| `src/File/FileStoreTransaction.php` | [004](../004-image-fields/spec.md): Runs a callback in a database transaction of the setting repository and, for a StagedSettingFileStoreInterface, in a... | FR-FILE-001 |
| `src/File/FilesystemSettingFileStore.php` | [004](../004-image-fields/spec.md): Filesystem store of the image setting files (1.7, owner decision D-20261109-03; opt-in with `files.store: filesystem`... | FR-FILE-001 |
| `src/File/ImageMetadataStripper.php` | [004](../004-image-fields/spec.md): Removes metadata and trailing data from a PNG, JPEG or WebP **without touching the pixel data** (1.7.1): the kept seg... | FR-FILE-001 |
| `src/File/PathGuard.php` | [004](../004-image-fields/spec.md): Where a path really points, also before it exists (1.7, audit JG-L6): the path is normalised lexically (`//`, `.`, `.... | FR-FILE-001 |
| `src/File/StagedSettingFileStoreInterface.php` | [004](../004-image-fields/spec.md): A file store whose writes cannot be rolled back with the database (1.7, audit JG-M1): inside a unit opened with self:... | FR-FILE-001 |
| `src/Form/SettingColorType.php` | Colour input of a SettingType::Color field (1.12): the hex text input (`#rrggbb`) is the submitted value, paired with... | FR-UI-003 |
| `src/Form/SettingImageCrop.php` | Opt-in crop step of an image setting field (1.8, owner decision D-20261109-09), on UiKit's image crop (2.43, ImageCro... | FR-UI-003 |
| `src/Form/ThemeCardsType.php` | Theme-card picker (1.6, IS-2): a single choice rendered as a radio group of cards, each with a colour swatch of the o... | FR-UI-003 |
| `src/Repository/RepositorySettingRowReader.php` | Store and persistence: Default SettingRowReaderInterface: maps the rows of the aliased SettingValueRepositoryInterface to StoredSettingRows... | FR-ORM-001 |
| `src/Repository/RepositorySettingRowWriter.php` | Store and persistence: Default SettingRowWriterInterface: maps the rows onto the entities of the aliased SettingValueRepositoryInterface and... | FR-ORM-001 |
| `src/Repository/SectionLock.php` | Store and persistence: The write lock of one settings section (1.19, Beacon spec 239): a sentinel row of the settings table under the reserv... | FR-ORM-001 |
| `src/Repository/SectionLockingRepositoryInterface.php` | Store and persistence: Optional capability of a SettingValueRepositoryInterface (1.19, Beacon spec 239): the writers of one section are seri... | FR-ORM-001 |
| `src/Repository/SettingRowReaderInterface.php` | Store and persistence: Read port of the stored settings as persistence-neutral rows (1.10, DDD P2): an application that reports on, audits o... | FR-ORM-001 |
| `src/Repository/SettingRowWriterInterface.php` | Store and persistence: Write port of the raw settings store with persistence-neutral rows (1.11, architecture bar rule 3): the counterpart o... | FR-ORM-001 |
| `src/Repository/StoredSecretStateInterface.php` | Store and persistence: Optional capability of a SettingValueRepositoryInterface (1.19, audit QQ-L-1): the stored form of the secret rows of... | FR-ORM-001 |
| `src/Repository/StoredSettingRow.php` | Store and persistence: Persistence-neutral, read-only view of one stored setting (1.10, DDD P2): what a SettingValueRepositoryInterface row... | FR-ORM-001 |
| `src/Resources/public/css/nowo-instance-settings.css` | Stylesheet `nowo-instance-settings.css` | FR-UI-001 |
| `src/Resources/public/css/nowo-instance-settings.unlayered.css` | Stylesheet `nowo-instance-settings.unlayered.css` | FR-UI-001 |
| `src/Resources/public/js/nowo-instance-settings-color.js` | Built browser script `nowo-instance-settings-color.js` | FR-UI-001 |
| `src/Resources/public/js/nowo-instance-settings-reveal.js` | Built browser script `nowo-instance-settings-reveal.js` | FR-UI-001 |
| `src/Resources/public/js/nowo-instance-settings-tabs.js` | Built browser script `nowo-instance-settings-tabs.js` | FR-UI-001 |
| `src/Resources/public/js/nowo-instance-settings-test.js` | Built browser script `nowo-instance-settings-test.js` | FR-UI-001 |
| `src/Resources/views/Form/color.html.twig` | Twig template `color.html.twig` | FR-UI-001 |
| `src/Resources/views/Form/theme_cards.html.twig` | Twig template `theme_cards.html.twig` | FR-UI-001 |
| `src/Resources/views/settings/_color_assets.html.twig` | Admin UI: Twig template `_color_assets.html.twig` | FR-UI-004 |
| `src/Resources/views/settings/_secret_lock.html.twig` | Admin UI: Twig template `_secret_lock.html.twig` | FR-UI-004 |
| `src/Resources/views/settings/_secret_reveal.html.twig` | Admin UI: Twig template `_secret_reveal.html.twig` | FR-UI-004 |
| `src/Resources/views/settings/_section_form_tabs.html.twig` | Admin UI: Twig template `_section_form_tabs.html.twig` | FR-UI-004 |
| `src/Resources/views/settings/_section_test.html.twig` | Admin UI: Twig template `_section_test.html.twig` | FR-UI-004 |
| `src/Resources/views/settings/_styles.html.twig` | Admin UI: Twig template `_styles.html.twig` | FR-UI-004 |
| `src/Secret/RevealRefusalLog.php` | Portability: The refusal line of a secret reveal (1.15.1, audit N2 / N3), shared by SecretRevealer and the UI gate (InstanceSettin... | FR-PORT-004 |
| `src/Secret/SecretRevealer.php` | Portability: Reads one stored secret back for a user who may reveal it (1.15, owner decision of 2026-11-13: an explicit Reveal act... | FR-PORT-004 |
| `src/Section/HiddenSecrets.php` | What a save does with a secret typed into a provider-dependent row hidden for the submitted controlling value (1.9, `... | FR-SEC-003 |
| `src/Section/SectionGroups.php` | Resolves the field groups of a section page (1.14): which fields each subtab holds. | FR-SEC-003 |
| `src/Section/SettingsGroup.php` | One field group of a settings section (1.14): a subtab of the section page when `web_ui.section_layout` is `tabs`.  A... | FR-SEC-003 |
| `src/Section/SettingsSectionGroupsInterface.php` | Optional interface of a SettingsSectionInterface (1.14): the field groups of a long section, shown as subtabs when `w... | FR-SEC-003 |
| `src/Section/SettingsSectionHiddenSecretsInterface.php` | Optional interface of a SettingsSectionInterface (1.9): overrides the bundle-wide `web_ui.hidden_secrets` for this se... | FR-SEC-003 |
| `src/Section/SettingsSectionSaveFilterInterface.php` | Optional interface of a SettingsSectionInterface (1.5): rewrites the values of a save before they are normalized and... | FR-SEC-003 |
| `src/SectionTest/DoctrineSectionTestStateStorage.php` | Doctrine storage of the section test state (1.18): rows of the settings table (`instance_setting_value`, with `doctri... | FR-SECTIONTEST-001 |
| `src/SectionTest/LeakGuard.php` | Keeps the stored secrets of a section out of a section test report (1.18), best effort: every key and every parameter... | FR-SECTIONTEST-001 |
| `src/SectionTest/SectionTestContext.php` | What a SettingsSectionTesterInterface gets (1.18): the section, the field group under test (null = the whole section)... | FR-SECTIONTEST-001 |
| `src/SectionTest/SectionTestRunner.php` | Runs the tester of a settings section, or of one field group of it, against the STORED values (1.18): the server side... | FR-SECTIONTEST-001 |
| `src/SectionTest/SectionTestStateRow.php` | One row of the section test state (1.18): a last report, a running marker or a rate window slot, as read from the store. | FR-SECTIONTEST-001 |
| `src/SectionTest/SectionTestStateStorageInterface.php` | Atomic primitives of the section test state (1.18, audit PU-L2 / L3): an insert that fails when the (section, name) r... | FR-SECTIONTEST-001 |
| `src/SectionTest/SectionTestStore.php` | The section test state kept in the bundle's own store (1.18): the last report of each tested section / group, the "on... | FR-SECTIONTEST-001 |
| `src/SectionTest/SectionTesterRegistry.php` | The registered section testers (1.18, AsSettingsTester): which section and group has one, and the tester itself, fetc... | FR-SECTIONTEST-001 |
| `src/SectionTest/SettingsSectionTesterInterface.php` | Tests that the STORED values of a settings section, or of one field group of it, really connect (1.18): the Test butt... | FR-SECTIONTEST-001 |
| `src/SectionTest/StoredTestReport.php` | The last report of a section test (1.18), as stored in the bundle's store and shown under the Test button: the sectio... | FR-SECTIONTEST-001 |
| `src/SectionTest/TestCheck.php` | One check of a section test (1.18): a translation key for its label, its status, and an optional hint (a translation... | FR-SECTIONTEST-001 |
| `src/SectionTest/TestReport.php` | What a SettingsSectionTesterInterface answers (1.18): its checks, in order, and their overall status. | FR-SECTIONTEST-001 |
| `src/SectionTest/TestStatus.php` | Outcome of one check of a section test, and the overall outcome of a TestReport (1.18). | FR-SECTIONTEST-001 |
| `src/Seed/SeedFileException.php` | [002](../002-seed-from-fallbacks/spec.md): A seed file (or seed data) refused by SeedFileSeeder before anything was written (1.7, IS-3). | FR-SEED-002 |
| `src/Seed/SeedFileSeeder.php` | [002](../002-seed-from-fallbacks/spec.md): Loads a host **seed file** (1.7, IS-3): the initial data of an instance — plain values and images — committed in the... | FR-SEED-002 |
| `src/Settings/SectionSnapshot.php` | What a section looked like when an editor opened it (1.19, Beacon spec 239): one fingerprint per field (and per expir... | FR-STORE-001 |
| `src/Settings/SettingsMemoObserver.php` | Drops the settings memo (InstanceSettings) when a transaction of the bundle's connection, or one of its savepoints, r... | FR-STORE-001 |
| `src/Settings/SnapshotSettingsWriterInterface.php` | Saves a section form without losing what another editor saved meanwhile (1.19, Beacon spec 239). | FR-STORE-001 |
| `src/Transaction/InstanceSettingsTransactionRunner.php` | 1.19 (Beacon spec 239): the runner of every write unit of the settings store, registered as `nowo_instance_settings.t... | FR-TRANSACTION-001 |
| `src/Transaction/StorageFailureListener.php` | 1.19 (Beacon spec 239): a storage failure of a route of the bundle answers 503 or 409 instead of 500. | FR-TRANSACTION-001 |
| `src/Ui/ImagePreview.php` | Admin UI: Admin preview of the image stored in an image field (1.5, audit IO Q3): the file route URL of the stored original, pr... | FR-UI-004 |
| `src/Ui/SecretReveal.php` | Admin UI: What `settings/_secret_reveal.html.twig` needs to render the Reveal control of the revealable secret rows of one sect... | FR-UI-004 |
| `src/Ui/SectionGroupTab.php` | Admin UI: One subtab of a section page (1.14): its code, label, fields and error count. | FR-UI-004 |
| `src/Ui/SectionGroupTabs.php` | Admin UI: The subtabs of a section page (1.14, `web_ui.section_layout: tabs`): one per resolved field group, rendered by `setti... | FR-UI-004 |
| `src/Ui/SectionLayout.php` | Admin UI: How a section page lays out its field groups (1.14, `web_ui.section_layout`). | FR-UI-004 |
| `src/Ui/SectionNavigation.php` | Admin UI: Presentation of the sections in the bundle's settings page (1.16): `web_ui.section_icons` (section name => icon, over... | FR-UI-004 |
| `src/Ui/SectionTestButton.php` | Admin UI: One Test button of a section page (1.18): the whole section (`group` null) or one field group, with the label of that... | FR-UI-004 |
| `src/Ui/SectionTestControls.php` | Admin UI: What `settings/_section_test.html.twig` needs to render the Test buttons of one section (1.18): the endpoint URL, the... | FR-UI-004 |
| `src/Ui/SectionTestView.php` | Admin UI: The translated view of a stored section test report (1.18): what the page shows under a Test button and what the test... | FR-UI-004 |

## Totals

- Mapped files: **205** of the 205 files under `src/` (re-audited 2026-11-17).
- FR-RS-001..005 (1.20, runtime settings): source, writer, section version tokens, admin guard, ports binding; see the linked docs.
- Re-audit: run the audit command and compare with this table on every PR that adds, moves or removes a file under `src/`.
