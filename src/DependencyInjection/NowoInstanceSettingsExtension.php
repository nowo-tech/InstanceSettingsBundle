<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection;

use InvalidArgumentException;
use LogicException;
use Nowo\Brand\Contract\BrandAssetReaderInterface;
use Nowo\Brand\Contract\BrandAssetSlot;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistry;
use Nowo\DoctrineEncryptBundle\Util\EncryptUtil;
use Nowo\InstanceSettingsBundle\Attribute\AsSettingsSection;
use Nowo\InstanceSettingsBundle\Brand\AppearanceSection;
use Nowo\InstanceSettingsBundle\Brand\BrandDefaults;
use Nowo\InstanceSettingsBundle\Brand\BrandPalettes;
use Nowo\InstanceSettingsBundle\Brand\BrandSection;
use Nowo\InstanceSettingsBundle\Brand\BrandTwigExtension;
use Nowo\InstanceSettingsBundle\Brand\DefaultAsset;
use Nowo\InstanceSettingsBundle\Brand\InstanceBrandAssetReader;
use Nowo\InstanceSettingsBundle\Brand\InstanceBrandProvider;
use Nowo\InstanceSettingsBundle\Command\ExportSettingsCommand;
use Nowo\InstanceSettingsBundle\Command\ImportSettingsCommand;
use Nowo\InstanceSettingsBundle\Command\PurgeFilesCommand;
use Nowo\InstanceSettingsBundle\Command\SeedCommand;
use Nowo\InstanceSettingsBundle\Command\SeedFromFallbacksCommand;
use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\BrandBridgePass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\FileStoreMiddlewarePass;
use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionMiddleware;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\Doctrine\TablePrefixListener;
use Nowo\InstanceSettingsBundle\Embed\SettingsSectionEmbedder;
use Nowo\InstanceSettingsBundle\Event\AfterCommitEventDispatcher;
use Nowo\InstanceSettingsBundle\EventListener\InstanceSettingsAccessListener;
use Nowo\InstanceSettingsBundle\File\DoctrineSettingFileStore;
use Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore;
use Nowo\InstanceSettingsBundle\File\ImageProcessor;
use Nowo\InstanceSettingsBundle\File\PathGuard;
use Nowo\InstanceSettingsBundle\File\SettingFileController;
use Nowo\InstanceSettingsBundle\File\SettingFileStoreInterface;
use Nowo\InstanceSettingsBundle\File\SettingFileUrls;
use Nowo\InstanceSettingsBundle\Form\PortabilityExportType;
use Nowo\InstanceSettingsBundle\Form\PortabilityImportType;
use Nowo\InstanceSettingsBundle\Form\SettingImageCrop;
use Nowo\InstanceSettingsBundle\Form\SettingImageType;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Form\ThemeCardsType;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\JsonUploadReader;
use Nowo\InstanceSettingsBundle\Portability\PortabilityProviderInterface;
use Nowo\InstanceSettingsBundle\Repository\DoctrineSettingValueRepository;
use Nowo\InstanceSettingsBundle\Repository\RepositorySettingRowReader;
use Nowo\InstanceSettingsBundle\Repository\RepositorySettingRowWriter;
use Nowo\InstanceSettingsBundle\Repository\SettingRowReaderInterface;
use Nowo\InstanceSettingsBundle\Repository\SettingRowWriterInterface;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRouteLoader;
use Nowo\InstanceSettingsBundle\Secret\DoctrineEncryptSecretSealer;
use Nowo\InstanceSettingsBundle\Secret\SecretSealerInterface;
use Nowo\InstanceSettingsBundle\Section\HiddenSecrets;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\AllowAllInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\ConfigurableInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Seed\FallbackSeeder;
use Nowo\InstanceSettingsBundle\Seed\SeedFileSeeder;
use Nowo\InstanceSettingsBundle\Seed\TransactionStateGuard;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Twig\InstanceSettingsTwigExtension;
use Nowo\InstanceSettingsBundle\Ui\SettingsPageFactory;
use Nowo\InstanceSettingsBundle\Ui\SettingsPages;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function in_array;
use function interface_exists;
use function is_array;
use function is_bool;
use function is_string;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function trim;

/**
 * Loads `nowo_instance_settings`: the settings store, sections registry, fallback seeder, portability, CLI, and (when enabled) the
 * admin UI with its access checker, route loader and FormKit profile.
 */
final class NowoInstanceSettingsExtension extends Extension implements PrependExtensionInterface
{
    /** Doctrine mapping name of the opt-in file entity (`files.enabled: true`). */
    public const FILES_MAPPING = 'NowoInstanceSettingsFiles';

    /** Parameter set by prepend(): whether the file entity mapping was prepended (audit IO-I10). */
    public const FILES_MAPPED_PARAMETER = 'nowo_instance_settings.files.mapped';

    /** Parameter set by prepend() with `doctrine.entity_manager` (1.11): the entities are mapped on that manager only. */
    public const DEDICATED_MAPPING_PARAMETER = 'nowo_instance_settings.doctrine.dedicated_mapping';

    /** Parameter set by prepend() with `doctrine.entity_manager` and the files enabled (1.11): the file entity too. */
    public const DEDICATED_FILES_MAPPING_PARAMETER = 'nowo_instance_settings.doctrine.dedicated_files_mapping';

    /**
     * @param bool|null $brandContracts whether nowo-tech/brand-contracts is installed (null = detect; tests)
     */
    public function __construct(
        private readonly ?bool $brandContracts = null,
    ) {
    }

    /**
     * `nowo_instance_settings`.
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Prepends the Doctrine mapping of the bundle entity (plus the file entity with `files.enabled: true`) and the
     * FormKit profile of the bundle forms.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine')) {
            $mappings = [
                'NowoInstanceSettingsBundle' => [
                    'type'      => 'attribute',
                    'dir'       => __DIR__ . '/../Entity',
                    'prefix'    => 'Nowo\InstanceSettingsBundle\Entity',
                    'alias'     => 'NowoInstanceSettings',
                    'is_bundle' => false,
                ],
            ];
            // Opt-in (1.4): hosts without image fields keep the 1.3 schema (no file table, no migration diff).
            // brand.enabled (1.5) implies the files: decided here, in this extension's own prepend (audit IO-I10).
            $rawConfigs  = $container->getExtensionConfig(Configuration::ALIAS);
            $filesMapped = self::filesEnabled($rawConfigs);
            // load() compares with this: a flag another extension prepends after this one fails with a clear message.
            $container->setParameter(self::FILES_MAPPED_PARAMETER, $filesMapped);
            // 1.11 (rule 6): with doctrine.entity_manager the bundle maps its entities on that manager only, through
            // DoctrineOrmMappingsPass + EntityManagerMappingPass (registered by the bundle class); nothing is
            // prepended to the default manager.
            if (self::rawEntityManager($rawConfigs) !== null) {
                $container->setParameter(self::DEDICATED_MAPPING_PARAMETER, true);
                if ($filesMapped) {
                    $container->setParameter(self::DEDICATED_FILES_MAPPING_PARAMETER, true);
                }
                $this->prependFormKitProfile($container);

                return;
            }
            if ($filesMapped) {
                $mappings[self::FILES_MAPPING] = [
                    'type'      => 'attribute',
                    'dir'       => __DIR__ . '/../FileEntity',
                    'prefix'    => 'Nowo\InstanceSettingsBundle\FileEntity',
                    'alias'     => 'NowoInstanceSettingsFiles',
                    'is_bundle' => false,
                ];
            }
            $container->prependExtensionConfig('doctrine', ['orm' => ['mappings' => $mappings]]);
        }

        $this->prependFormKitProfile($container);
    }

    /**
     * Registers the services from the processed configuration.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $this->assertLiteralBooleans($config);

        /** @var array{doctrine: array{table_prefix: string, entity_manager: string|null}, web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>, hidden_secrets: string, hidden_secrets_notice: bool}, security: array{access_roles: list<string>, area_roles: array<string, list<string>>, access_checker: string|null, allow_unauthenticated: bool}, portability: array{web_ui: bool, schema: string, max_upload_bytes: int, sealed_secrets: bool, max_file_bytes: int}, files: array{enabled: bool, path_prefix: string, orphan_retention: int, store: string, directory: string, crop: array{enabled: bool, shape: string|null, quality: float|null, labels: array<string, string>}}, seed: array{file: string|null, base_dir: string}, brand: array{enabled: bool, public_dir: string, fallback_provider: string|null, defaults: array<string, mixed>, extra_images: array<string, array{label: string|null, help: string|null, max_width: int, max_height: int, max_bytes: int, square: bool, default: string|null}>}} $config */
        $webUi       = $config['web_ui']['enabled'];
        $portability = $config['portability'];

        if ($webUi && !$config['security']['allow_unauthenticated'] && !$this->hasSecurityBundle($container)) {
            throw new LogicException('NowoInstanceSettingsBundle: the admin UI requires symfony/security-bundle (or security.allow_unauthenticated: true in dev / demo only).');
        }

        $this->assertPortabilityArea($config);
        $this->assertFilePrefix($config['files']['path_prefix'], $config['web_ui']['path_prefix']);

        $container->setParameter('nowo_instance_settings.doctrine.table_prefix', $config['doctrine']['table_prefix']);
        $this->assertDedicatedMapping($container, $config['doctrine']['entity_manager']);
        $container->setParameter(FileStoreMiddlewarePass::ENTITY_MANAGER_PARAMETER, $config['doctrine']['entity_manager']);
        $container->setParameter('nowo_instance_settings.web_ui.enabled', $webUi);
        $container->setParameter('nowo_instance_settings.web_ui.path_prefix', $config['web_ui']['path_prefix']);
        $container->setParameter('nowo_instance_settings.web_ui.layout_template', $config['web_ui']['layout_template']);
        $container->setParameter('nowo_instance_settings.web_ui.templates', $config['web_ui']['templates']);
        $container->setParameter('nowo_instance_settings.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
        $container->setParameter('nowo_instance_settings.portability.schema', $portability['schema']);
        $container->setParameter('nowo_instance_settings.files.path_prefix', $config['files']['path_prefix']);
        $container->setParameter('nowo_instance_settings.files.orphan_retention', $config['files']['orphan_retention']);

        $container->registerForAutoconfiguration(SettingsSectionInterface::class)->addTag(NowoInstanceSettingsBundle::SECTION_TAG);
        $container->registerForAutoconfiguration(PortabilityProviderInterface::class)->addTag(NowoInstanceSettingsBundle::PORTABILITY_PROVIDER_TAG);
        $container->registerAttributeForAutoconfiguration(AsSettingsSection::class, static function (ChildDefinition $definition, AsSettingsSection $attribute): void {
            $definition->addTag(NowoInstanceSettingsBundle::SECTION_TAG, ['priority' => $attribute->priority]);
        });

        // brand.enabled (1.5) implies the files (its image fields need the file table).
        $brandEnabled = $config['brand']['enabled'];
        $filesEnabled = $config['files']['enabled'] || $brandEnabled;
        $container->setParameter('nowo_instance_settings.files.enabled', $filesEnabled);
        $container->setParameter(BrandBridgePass::PARAMETER, $brandEnabled);
        $this->assertFilesMapped($container, $filesEnabled);
        $this->assertLocalPath($container, $config['files']['directory'], 'files.directory');
        $this->assertLocalPath($container, $config['seed']['base_dir'], 'seed.base_dir');
        $this->registerStore($container, $config['doctrine'], $filesEnabled, $config['seed']);
        if ($filesEnabled && $config['files']['store'] === 'filesystem') {
            $this->assertFilesDirectoryNotPublic($container, $config['files']['directory'], $config['brand']['public_dir']);
        }
        $this->registerFiles($container, $config['doctrine'], $filesEnabled, $config['files'] + ['public_dir' => $config['brand']['public_dir']]);
        $this->registerPortability($container, $portability, $filesEnabled);
        // The access checker / guard also serve kits (their own actions, CLI) when the admin UI is off.
        $this->registerAccess($container, $config['security']);
        $this->registerWebUi($container, $config);
        if ($brandEnabled) {
            $this->registerBrand($container, $config['brand']);
        }
    }

    /**
     * The brand bridge (1.5, spec 220 S3): the `brand` and `appearance` sections, the contract provider and asset
     * reader, `nowo_brand()`. UiKit's appearance provider is re-aliased by {@see BrandBridgePass}.
     *
     * @param array{enabled: bool, public_dir: string, fallback_provider: string|null, preset_picker?: string, defaults: array<string, mixed>, extra_images: array<string, array{label: string|null, help: string|null, max_width: int, max_height: int, max_bytes: int, square: bool, default: string|null}>} $brand
     */
    private function registerBrand(ContainerBuilder $container, array $brand): void
    {
        if (!($this->brandContracts ?? interface_exists(BrandProviderInterface::class))) {
            throw new LogicException('NowoInstanceSettingsBundle: brand.enabled needs nowo-tech/brand-contracts (composer require nowo-tech/brand-contracts).');
        }

        /** @var array{name: string, short_name: string|null, eyebrow: array{text: string|null, key: string|null, domain: string}, wordmark: string|null} $defaults */
        $defaults  = $brand['defaults'];
        $publicDir = (string) $container->getParameterBag()->resolveValue($brand['public_dir']);
        $assets    = [];
        $paths     = [];
        foreach (BrandSection::FIXED_SLOTS as $slot) {
            $paths[$slot] = $brand['defaults'][$slot] ?? null;
        }
        $extras = [];
        foreach ($brand['extra_images'] as $id => $rules) {
            $paths[BrandAssetSlot::extra((string) $id)] = $rules['default'];
            unset($rules['default']);
            $extras[(string) $id] = $rules;
        }
        foreach ($paths as $slot => $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            try {
                $assets[$slot] = DefaultAsset::fromFile($slot, $path, $publicDir)->toArray();
            } catch (InvalidArgumentException $e) {
                throw new InvalidConfigurationException('nowo_instance_settings.brand: ' . $e->getMessage(), 0, $e);
            }
            $container->addResource(new FileResource($assets[$slot]['path']));
        }

        $container->setDefinition(BrandDefaults::class, new Definition(BrandDefaults::class, [
            $defaults['name'],
            $defaults['short_name'],
            $defaults['eyebrow']['text'],
            $defaults['eyebrow']['key'],
            $defaults['eyebrow']['domain'],
            $defaults['wordmark'],
            $assets,
            $extras,
        ]));
        $container->setDefinition(BrandPalettes::class, new Definition(BrandPalettes::class, [
            // UiKit's private, never-decorated defaults (2.40); absent while UiKit's appearance is disabled.
            new Reference('nowo_ui_kit.appearance.defaults_provider', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('nowo_ui_kit.appearance.preset_registry', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        $container->setDefinition(InstanceBrandProvider::class, (new Definition(InstanceBrandProvider::class, [
            new Reference(InstanceSettingsInterface::class),
            new Reference(BrandDefaults::class),
            new Reference(BrandPalettes::class),
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));
        // A host alias of the interface in its own services wins (application definitions are applied after the
        // extensions).
        $container->setAlias(BrandProviderInterface::class, InstanceBrandProvider::class)->setPublic(true);

        $container->setDefinition(InstanceBrandAssetReader::class, new Definition(InstanceBrandAssetReader::class, [
            new Reference(BrandDefaults::class),
            new Reference(SettingFileStoreInterface::class),
        ]));
        $container->setAlias(BrandAssetReaderInterface::class, InstanceBrandAssetReader::class)->setPublic(true);

        $fallbacks = is_string($brand['fallback_provider']) && $brand['fallback_provider'] !== '' ? new Reference($brand['fallback_provider']) : null;
        $container->setDefinition(BrandSection::class, (new Definition(BrandSection::class, [
            new Reference(BrandDefaults::class),
            $fallbacks,
        ]))->addTag(NowoInstanceSettingsBundle::SECTION_TAG));
        $container->setDefinition(AppearanceSection::class, (new Definition(AppearanceSection::class, [
            new Reference(BrandPalettes::class),
            new Reference(InstanceBrandProvider::class),
            $fallbacks,
            ($brand['preset_picker'] ?? Configuration::PRESET_PICKER_SELECT) === Configuration::PRESET_PICKER_CARDS,
        ]))->addTag(NowoInstanceSettingsBundle::SECTION_TAG));

        $container->setDefinition(BrandTwigExtension::class, (new Definition(BrandTwigExtension::class, [
            new Reference(InstanceBrandProvider::class),
        ]))->addTag('twig.extension'));
    }

    /**
     * 1.11: the mapping is decided in prepend() from the raw `doctrine.entity_manager`; a value another extension
     * prepends after this one (or a non-literal one) would leave the entities on the wrong manager.
     */
    private function assertDedicatedMapping(ContainerBuilder $container, ?string $entityManager): void
    {
        if (!$container->hasParameter(self::FILES_MAPPED_PARAMETER)) {
            // prepend() did not run with DoctrineBundle registered: nothing was mapped either way.
            return;
        }
        $dedicated = $container->hasParameter(self::DEDICATED_MAPPING_PARAMETER);
        if ($dedicated !== ($entityManager !== null && trim($entityManager) !== '')) {
            throw new LogicException('NowoInstanceSettingsBundle: doctrine.entity_manager must be a literal manager name set in the host configuration (config/packages/nowo_instance_settings.yaml): the entity mapping is decided before the configuration is processed.');
        }
    }

    /**
     * Audit IO-I10: the file table is mapped in prepend() from the raw configs. If another extension prepends
     * `files.enabled` / `brand.enabled` after that, the store would be registered without its entity: refuse at compile
     * time instead of a Doctrine mapping error on the first image save.
     */
    private function assertFilesMapped(ContainerBuilder $container, bool $filesEnabled): void
    {
        if ($filesEnabled && $container->hasParameter(self::FILES_MAPPED_PARAMETER) && $container->getParameter(self::FILES_MAPPED_PARAMETER) === false) {
            throw new LogicException('NowoInstanceSettingsBundle: files.enabled (or brand.enabled) was set by a configuration prepended after this bundle, so the file table is not mapped. Set it in the host configuration (config/packages/nowo_instance_settings.yaml).');
        }
    }

    /**
     * Default FormKit profile of the bundle forms: bundle translation domain, no auto help / placeholder keys, no
     * framework classes (the UiKit settings scaffold theme paints the fields through its slots, REQ-UI-003).
     *
     * @return array<string, mixed>
     */
    public static function formKitProfile(): array
    {
        return [
            'alias'              => NowoInstanceSettingsBundle::FORM_KIT_PROFILE,
            'translation_domain' => NowoInstanceSettingsBundle::TRANSLATION_DOMAIN,
            'auto_help'          => false,
            'auto_placeholder'   => false,
            'defaults'           => ['attr' => [], 'row_attr' => []],
            'field_types'        => [],
        ];
    }

    /**
     * Whether the raw `nowo_instance_settings` configs enable the files: the last literal `files.enabled` wins, and a
     * literal `brand.enabled: true` (1.5, the last one wins) enables them too. Read in prepend(), before the
     * configuration is processed: a non-boolean value never enables the mapping, and load() refuses it anyway
     * (literal booleans only).
     *
     * @param array<array<string, mixed>> $configs
     */
    public static function filesEnabled(array $configs): bool
    {
        return self::rawSwitch($configs, 'files') || self::rawSwitch($configs, 'brand');
    }

    /**
     * The last literal, non-empty `doctrine.entity_manager` of the raw configs (null = not set: the default manager).
     *
     * @param array<array<string, mixed>> $configs
     */
    public static function rawEntityManager(array $configs): ?string
    {
        $name = null;
        foreach ($configs as $config) {
            $doctrine = $config['doctrine'] ?? null;
            if (is_array($doctrine) && array_key_exists('entity_manager', $doctrine)) {
                $name = is_string($doctrine['entity_manager']) && trim($doctrine['entity_manager']) !== '' ? trim($doctrine['entity_manager']) : null;
            }
        }

        return $name;
    }

    /**
     * The last literal `<node>.enabled` of the raw configs (false when none is set or it is not a boolean).
     *
     * @param array<array<string, mixed>> $configs
     */
    private static function rawSwitch(array $configs, string $node): bool
    {
        $enabled = false;
        foreach ($configs as $config) {
            $branch = $config[$node] ?? null;
            if (is_array($branch) && array_key_exists('enabled', $branch)) {
                $enabled = $branch['enabled'] === true;
            }
        }

        return $enabled;
    }

    /**
     * @param array{table_prefix: string, entity_manager: string|null} $doctrine
     * @param array{file: string|null, base_dir: string} $seed
     */
    private function registerStore(ContainerBuilder $container, array $doctrine, bool $filesEnabled, array $seed): void
    {
        $container->setDefinition(SettingsSectionRegistry::class, new Definition(SettingsSectionRegistry::class, [
            new TaggedIteratorArgument(NowoInstanceSettingsBundle::SECTION_TAG),
            null,
            $filesEnabled,
        ]));

        // 1.11 (rule 6): the bundle's private entity manager on the connection of the manager that maps the entities;
        // shared by the repository and the database file store. Never flushes or resets an application manager.
        $container->setDefinition(SettingsEntityManager::class, (new Definition(SettingsEntityManager::class, [
            new Reference('doctrine'),
            $doctrine['entity_manager'],
        ]))->addTag('kernel.reset', ['method' => 'reset']));

        $container->setDefinition(DoctrineSettingValueRepository::class, new Definition(DoctrineSettingValueRepository::class, [
            new Reference('doctrine'),
            $doctrine['entity_manager'],
            new Reference(SettingsEntityManager::class),
        ]));
        $container->setAlias(SettingValueRepositoryInterface::class, DoctrineSettingValueRepository::class);

        $container->setDefinition(TablePrefixListener::class, (new Definition(TablePrefixListener::class, [$doctrine['table_prefix']]))
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));

        $container->setDefinition(ValueNormalizer::class, new Definition(ValueNormalizer::class, [
            new Reference('validator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));

        // 1.10 (DDD P2): SettingsChangedEvent is dispatched after the outermost commit of the bundle's connection,
        // dropped on rollback; the middleware reports the real transactions (the file store joins it, JG-M1).
        $container->setDefinition(AfterCommitEventDispatcher::class, (new Definition(AfterCommitEventDispatcher::class, [
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('kernel.reset', ['method' => 'reset']));
        $container->setDefinition(FileStoreTransactionMiddleware::class, (new Definition(FileStoreTransactionMiddleware::class, [new Reference(AfterCommitEventDispatcher::class)]))
            ->addTag('doctrine.middleware'));

        // 1.10 (DDD P2): persistence-neutral read view of the stored rows, over whichever repository is aliased.
        $container->setDefinition(RepositorySettingRowReader::class, new Definition(RepositorySettingRowReader::class, [
            new Reference(SettingValueRepositoryInterface::class),
        ]));
        $container->setAlias(SettingRowReaderInterface::class, RepositorySettingRowReader::class)->setPublic(true);
        // 1.11 (rule 3): the DTO write counterpart, over the same repository.
        $container->setDefinition(RepositorySettingRowWriter::class, new Definition(RepositorySettingRowWriter::class, [
            new Reference(SettingValueRepositoryInterface::class),
        ]));
        $container->setAlias(SettingRowWriterInterface::class, RepositorySettingRowWriter::class)->setPublic(true);

        $container->setDefinition(InstanceSettings::class, (new Definition(InstanceSettings::class, [
            new Reference(SettingValueRepositoryInterface::class),
            new Reference(SettingsSectionRegistry::class),
            new Reference(ValueNormalizer::class),
            new Reference(AfterCommitEventDispatcher::class),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $filesEnabled ? new Reference(SettingFileStoreInterface::class) : null,
            new Reference(ImageProcessor::class),
            new Reference(SettingFileUrls::class),
        ]))->addTag('kernel.reset', ['method' => 'reset']));
        $container->setAlias(InstanceSettingsInterface::class, InstanceSettings::class)->setPublic(true);

        $container->setDefinition(TransactionStateGuard::class, new Definition(TransactionStateGuard::class, [
            new Reference('doctrine'),
            $doctrine['entity_manager'],
        ]));

        // Public: host upgrade steps and Doctrine migrations may fetch it from the container.
        $container->setDefinition(FallbackSeeder::class, (new Definition(FallbackSeeder::class, [
            new Reference(SettingsSectionRegistry::class),
            new Reference(SettingValueRepositoryInterface::class),
            new Reference(InstanceSettingsInterface::class),
            new Reference(ValueNormalizer::class),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(TransactionStateGuard::class),
            new Reference(AfterCommitEventDispatcher::class),
            new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));

        $container->setDefinition(SeedFromFallbacksCommand::class, (new Definition(SeedFromFallbacksCommand::class, [
            new Reference(FallbackSeeder::class),
        ]))->addTag('console.command', ['command' => SeedFromFallbacksCommand::NAME]));

        // 1.7 (IS-3): the host seed file. Public: host-side importers call seed() / seedImage().
        $container->setDefinition(SeedFileSeeder::class, (new Definition(SeedFileSeeder::class, [
            new Reference(SettingsSectionRegistry::class),
            new Reference(SettingValueRepositoryInterface::class),
            new Reference(InstanceSettingsInterface::class),
            $seed['base_dir'],
            $seed['file'],
            $filesEnabled ? new Reference(SettingFileStoreInterface::class) : null,
            new Reference(ImageProcessor::class),
            new Reference(ValueNormalizer::class),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(TransactionStateGuard::class),
            new Reference(AfterCommitEventDispatcher::class),
            new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));

        $container->setDefinition(SeedCommand::class, (new Definition(SeedCommand::class, [
            new Reference(SeedFileSeeder::class),
        ]))->addTag('console.command', ['command' => SeedCommand::NAME]));

        $container->setDefinition(InstanceSettingsTwigExtension::class, (new Definition(InstanceSettingsTwigExtension::class, [
            new Reference(InstanceSettingsInterface::class),
        ]))->addTag('twig.extension'));
    }

    /**
     * Image field storage (1.4). The GD pipeline and the URL builder are always registered (stateless, no request work).
     * The database store, the anonymous-capable file controller and the purge command exist only with
     * `files.enabled: true` (the file table is mapped only then); the file route exists only when a section declares an
     * image field, which needs the flag.
     *
     * @param array{table_prefix: string, entity_manager: string|null} $doctrine
     * @param array{enabled: bool, path_prefix: string, orphan_retention: int, store: string, directory: string, public_dir: string} $files
     */
    private function registerFiles(ContainerBuilder $container, array $doctrine, bool $enabled, array $files): void
    {
        $retention = $files['orphan_retention'];
        $container->setDefinition(ImageProcessor::class, new Definition(ImageProcessor::class));
        $container->setDefinition(SettingFileUrls::class, new Definition(SettingFileUrls::class, [
            new Reference('router', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        if (!$enabled) {
            return;
        }

        $container->setDefinition(DoctrineSettingFileStore::class, new Definition(DoctrineSettingFileStore::class, [
            new Reference('doctrine'),
            $doctrine['entity_manager'],
            new Reference(SettingsEntityManager::class),
        ]));
        if ($files['store'] === 'filesystem') {
            // 1.7 (owner decision D-20261109-03): stable files per field in files.directory, no database blob.
            $container->setDefinition(FilesystemSettingFileStore::class, (new Definition(FilesystemSettingFileStore::class, [$files['directory'], ['%kernel.project_dir%/public', $files['public_dir']]]))
                ->addTag('kernel.reset', ['method' => 'reset']));
            // Follows the real transactions (a host transaction around a bundle call) for the file changes (JG-M1).
            $container->getDefinition(FileStoreTransactionMiddleware::class)->setArguments([
                new Reference(FilesystemSettingFileStore::class),
                new Reference(AfterCommitEventDispatcher::class),
            ]);
            $container->setAlias(SettingFileStoreInterface::class, FilesystemSettingFileStore::class);
        } else {
            $container->setAlias(SettingFileStoreInterface::class, DoctrineSettingFileStore::class);
        }

        // Not autoconfigured and outside the Controller namespace: the admin access listener never guards it; the
        // controller itself checks the section area of non-public fields.
        $container->setDefinition(SettingFileController::class, (new Definition(SettingFileController::class, [
            new Reference(SettingsSectionRegistry::class),
            new Reference(SettingFileStoreInterface::class),
            new Reference(AccessGuard::class),
            new Reference(InstanceSettingsInterface::class),
        ]))->setPublic(true)->addTag('controller.service_arguments'));

        $container->setDefinition(PurgeFilesCommand::class, (new Definition(PurgeFilesCommand::class, [
            new Reference(SettingsSectionRegistry::class),
            new Reference(SettingValueRepositoryInterface::class),
            new Reference(SettingFileStoreInterface::class),
            $retention,
            new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('console.command', ['command' => PurgeFilesCommand::NAME]));
    }

    /**
     * @param array{web_ui: bool, schema: string, max_upload_bytes: int, sealed_secrets: bool, max_file_bytes: int} $portability
     */
    private function registerPortability(ContainerBuilder $container, array $portability, bool $filesEnabled): void
    {
        $container->setDefinition(DoctrineEncryptSecretSealer::class, new Definition(DoctrineEncryptSecretSealer::class, [
            new Reference(EncryptUtil::class),
            null,
            new Reference(EncryptorRegistry::class),
        ]));
        $container->setAlias(SecretSealerInterface::class, DoctrineEncryptSecretSealer::class);

        $container->setDefinition(JsonUploadReader::class, new Definition(JsonUploadReader::class, [$portability['max_upload_bytes']]));

        $container->setDefinition(ConfigPortability::class, (new Definition(ConfigPortability::class, [
            new Reference(SettingsSectionRegistry::class),
            new Reference(InstanceSettingsInterface::class),
            new Reference(SettingValueRepositoryInterface::class),
            new Reference(SecretSealerInterface::class),
            new TaggedIteratorArgument(NowoInstanceSettingsBundle::PORTABILITY_PROVIDER_TAG),
            $portability['schema'],
            $portability['sealed_secrets'],
            new Reference(ValueNormalizer::class),
            new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $filesEnabled ? new Reference(SettingFileStoreInterface::class) : null,
            $portability['max_file_bytes'],
            new Reference(ImageProcessor::class),
        ]))->setPublic(true));

        $container->setDefinition(ExportSettingsCommand::class, (new Definition(ExportSettingsCommand::class, [
            new Reference(ConfigPortability::class),
        ]))->addTag('console.command', ['command' => ExportSettingsCommand::NAME]));

        $container->setDefinition(ImportSettingsCommand::class, (new Definition(ImportSettingsCommand::class, [
            new Reference(ConfigPortability::class),
            new Reference(JsonUploadReader::class),
        ]))->addTag('console.command', ['command' => ImportSettingsCommand::NAME]));
    }

    /**
     * @param array{access_roles: list<string>, area_roles: array<string, list<string>>, access_checker: string|null, allow_unauthenticated: bool} $security
     */
    private function registerAccess(ContainerBuilder $container, array $security): void
    {
        $customId = $security['access_checker'];
        if (is_string($customId) && $customId !== '') {
            $container->setAlias(InstanceSettingsAccessCheckerInterface::class, $customId)->setPublic(true);
        } elseif ($security['allow_unauthenticated']) {
            $container->setDefinition(AllowAllInstanceSettingsAccessChecker::class, new Definition(AllowAllInstanceSettingsAccessChecker::class));
            $container->setAlias(InstanceSettingsAccessCheckerInterface::class, AllowAllInstanceSettingsAccessChecker::class)->setPublic(true);
        } else {
            $areaRoles = [];
            foreach ($security['area_roles'] as $area => $roles) {
                $areaRoles[(string) $area] = Configuration::roles($roles);
            }
            $container->setDefinition(ConfigurableInstanceSettingsAccessChecker::class, new Definition(ConfigurableInstanceSettingsAccessChecker::class, [
                new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                Configuration::roles($security['access_roles']),
                $areaRoles,
            ]));
            $container->setAlias(InstanceSettingsAccessCheckerInterface::class, ConfigurableInstanceSettingsAccessChecker::class)->setPublic(true);
        }

        $container->setDefinition(AccessGuard::class, (new Definition(AccessGuard::class, [
            new Reference(InstanceSettingsAccessCheckerInterface::class),
            $security['allow_unauthenticated'],
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('security.firewall.map', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));
    }

    /**
     * The bundle default of the image crop step (1.8, `files.crop`): false when off, else its set options (shape,
     * quality, labels), checked against UiKit's image crop.
     *
     * @param array{enabled: bool, shape: string|null, quality: float|null, labels: array<string, string>} $crop
     *
     * @return array<string, mixed>|false
     */
    public static function cropDefault(array $crop): array|false
    {
        if (!$crop['enabled']) {
            return false;
        }
        $options = array_filter(['shape' => $crop['shape'], 'quality' => $crop['quality'], 'labels' => $crop['labels']], static fn (mixed $value): bool => $value !== null && $value !== []);
        try {
            return SettingImageCrop::bundleDefault($options);
        } catch (InvalidArgumentException $e) {
            throw new InvalidConfigurationException('nowo_instance_settings.files.crop: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array{web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>, hidden_secrets: string, hidden_secrets_notice: bool}, security: array{access_roles: list<string>, area_roles: array<string, list<string>>, access_checker: string|null, allow_unauthenticated: bool}, portability: array{web_ui: bool, schema: string, max_upload_bytes: int, sealed_secrets: bool, max_file_bytes: int}, files: array{enabled: bool, path_prefix: string, orphan_retention: int, store: string, directory: string, crop: array{enabled: bool, shape: string|null, quality: float|null, labels: array<string, string>}}} $config
     */
    private function registerWebUi(ContainerBuilder $container, array $config): void
    {
        $webUi              = $config['web_ui'];
        $portabilityEnabled = $config['portability']['web_ui'];

        $container->setDefinition(InstanceSettingsRouteLoader::class, (new Definition(InstanceSettingsRouteLoader::class, [
            $webUi['enabled'],
            $webUi['path_prefix'],
            $portabilityEnabled,
            null,
            new Reference(SettingsSectionRegistry::class),
            $config['files']['path_prefix'],
        ]))->addTag('routing.loader'));

        if (!$webUi['enabled']) {
            return;
        }

        foreach ([SettingsSectionType::class, ThemeCardsType::class, PortabilityImportType::class, PortabilityExportType::class] as $type) {
            $container->setDefinition($type, (new Definition($type))->addTag('form.type'));
        }
        // 1.8: the bundle default of the image crop step (files.crop) and the translator of its dialog texts.
        $container->setDefinition(SettingImageType::class, (new Definition(SettingImageType::class, [
            self::cropDefault($config['files']['crop']),
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('form.type'));

        $container->setDefinition(SettingsPages::class, new Definition(SettingsPages::class, [
            $webUi['layout_template'],
            $webUi['templates'],
        ]));
        $container->setDefinition(SettingsPageFactory::class, new Definition(SettingsPageFactory::class, [
            new Reference('router'),
            new Reference(SettingsPages::class),
        ]));

        $container->setDefinition(InstanceSettingsAccessListener::class, (new Definition(InstanceSettingsAccessListener::class, [
            new Reference(AccessGuard::class),
            $webUi['path_prefix'],
        ]))->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onKernelRequest', 'priority' => InstanceSettingsAccessListener::PRIORITY]));

        // 1.6 (IS-1): the section page, shared by the bundle route and host embeds (public: hosts fetch it in their
        // own controllers by autowiring).
        $container->setDefinition(SettingsSectionEmbedder::class, (new Definition(SettingsSectionEmbedder::class, [
            new Reference(SettingsSectionRegistry::class),
            new Reference(InstanceSettingsInterface::class),
            new Reference(AccessGuard::class),
            new Reference(SettingsPageFactory::class),
            new Reference('form.factory'),
            new Reference('twig'),
            new Reference('router'),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $portabilityEnabled,
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            // 1.9 (Beacon spec 222, owner Q4 = b): secrets typed into hidden provider rows, and the notice while dropped.
            HiddenSecrets::from($webUi['hidden_secrets']),
            $webUi['hidden_secrets_notice'],
        ]))->setPublic(true));

        // REQ-SF-004 rule 7: not autoconfigured (no `routing.controller` tag from #[Route]); the routes come only from
        // the `nowo_instance_settings` loader. Autowired only for AbstractController::setContainer().
        $container->setDefinition(SettingsController::class, (new Definition(SettingsController::class, [
            new Reference(SettingsSectionRegistry::class),
            new Reference(InstanceSettingsInterface::class),
            new Reference(AccessGuard::class),
            new Reference(SettingsPageFactory::class),
            new Reference(ConfigPortability::class),
            new Reference(JsonUploadReader::class),
            $portabilityEnabled,
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(SettingsSectionEmbedder::class),
        ]))
            ->setAutowired(true)
            ->setAutoconfigured(false)
            ->setPublic(true)
            ->addTag('controller.service_arguments')
            ->addTag('container.service_subscriber'));
    }

    /**
     * Registers the FormKit profile {@see NowoInstanceSettingsBundle::FORM_KIT_PROFILE} unless the host defines it.
     */
    private function prependFormKitProfile(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        foreach ($container->getExtensionConfig('nowo_form_kit') as $config) {
            $profiles = $config['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists(NowoInstanceSettingsBundle::FORM_KIT_PROFILE, $profiles)) {
                return;
            }
        }

        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => [NowoInstanceSettingsBundle::FORM_KIT_PROFILE => self::formKitProfile()],
        ]);
    }

    /**
     * Per-section `area_roles` without an explicit `_portability` list would let every `access_roles` user open the
     * import / export page; the web export / import is area-aware anyway, but the page must be an explicit decision.
     *
     * @param array{web_ui: array{enabled: bool}, security: array{area_roles: array<string, list<string>>, access_checker: string|null, allow_unauthenticated: bool}, portability: array{web_ui: bool}} $config
     */
    private function assertPortabilityArea(array $config): void
    {
        $security = $config['security'];
        if (!$config['web_ui']['enabled'] || !$config['portability']['web_ui'] || $security['allow_unauthenticated'] || (is_string($security['access_checker']) && $security['access_checker'] !== '')) {
            return;
        }

        $areas = array_keys($security['area_roles']);
        if ($areas !== [] && !in_array(InstanceSettingsAccessCheckerInterface::AREA_PORTABILITY, $areas, true)) {
            throw new InvalidConfigurationException('nowo_instance_settings.security.area_roles restricts sections but has no "_portability" entry: set security.area_roles._portability explicitly (the roles allowed to open the import / export page, including sealed secrets of the sections they may open), or set portability.web_ui: false.');
        }
    }

    /**
     * 1.7 (audit JG-L4): the filesystem store's directory must not be under the public web root
     * (`%kernel.project_dir%/public`, or `brand.public_dir`): the web server would serve the files directly, without
     * the file route's access check and headers.
     */
    private function assertFilesDirectoryNotPublic(ContainerBuilder $container, string $directory, string $publicDir): void
    {
        $bag = $container->getParameterBag();
        try {
            $dir   = (string) $bag->resolveValue($directory);
            $roots = [(string) $bag->resolveValue('%kernel.project_dir%/public'), (string) $bag->resolveValue($publicDir)];
        } catch (ParameterNotFoundException) {
            // Built outside a kernel (no kernel.project_dir): the store checks again when it is built.
            return;
        }
        if ($this->usesEnv($container, $dir)) {
            // 1.7.1: an %env(...)% directory is only known at run time; the store applies the same check when it is built.
            return;
        }
        foreach ($roots as $root) {
            if ($this->usesEnv($container, $root)) {
                continue;
            }
            if (PathGuard::isUnder($dir, $root)) {
                throw new LogicException(sprintf('NowoInstanceSettingsBundle: files.directory "%s" is under the public web root "%s": the web server would serve the images without the file route\'s access check and headers. Use a directory outside it, e.g. "%%kernel.project_dir%%/config/instance-files".', $dir, $root));
            }
        }
    }

    /**
     * 1.7.1: a literal `files.directory` / `seed.base_dir` must be a local directory path (not blank, no NUL, no
     * `://`). The tree lets '' through (see {@see Configuration::isPathLike()}); a value with an `%env(...)%` is
     * checked at run time by the store / seeder instead, once the variable is resolved.
     */
    private function assertLocalPath(ContainerBuilder $container, string $value, string $option): void
    {
        if ($this->usesEnv($container, $value)) {
            return;
        }
        if (!PathGuard::isLocalPath($value)) {
            throw new InvalidConfigurationException(sprintf('nowo_instance_settings.%s must be a local directory path, got "%s".', $option, $value));
        }
        try {
            $resolved = (string) $container->getParameterBag()->resolveValue($value);
        } catch (ParameterNotFoundException) {
            // Built outside a kernel: the store / seeder check again when they are built.
            return;
        }
        if (!PathGuard::isAbsolute($resolved)) {
            throw new InvalidConfigurationException(sprintf('nowo_instance_settings.%s must be an absolute path (e.g. "%%kernel.project_dir%%/…"; a relative one depends on the current directory of each process), got "%s".', $option, $value));
        }
    }

    /**
     * Whether `$value` holds an `%env(...)%` placeholder of the container.
     */
    private function usesEnv(ContainerBuilder $container, string $value): bool
    {
        $used = [];
        $container->resolveEnvPlaceholders($value, null, $used);

        return $used !== [];
    }

    /**
     * The file route must not sit under the admin prefix (nor the admin UI under it): public brand images must never
     * depend on the admin firewall, and the admin access listener guards its prefix by path.
     */
    private function assertFilePrefix(string $filePrefix, string $adminPrefix): void
    {
        $file  = rtrim($filePrefix, '/');
        $admin = rtrim($adminPrefix, '/');
        if ($file === $admin || str_starts_with($file . '/', $admin . '/') || str_starts_with($admin . '/', $file . '/')) {
            throw new InvalidConfigurationException(sprintf('nowo_instance_settings.files.path_prefix "%s" must be outside web_ui.path_prefix "%s" (and not contain it).', $filePrefix, $adminPrefix));
        }
    }

    /**
     * Whether SecurityBundle is registered (kernel.bundles; the extension list of the merge container holds only this
     * extension).
     */
    private function hasSecurityBundle(ContainerBuilder $container): bool
    {
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];

        return (is_array($bundles) && array_key_exists('SecurityBundle', $bundles)) || $container->hasExtension('security');
    }

    /**
     * Security switches must be literal booleans: an `%env(bool:…)%` placeholder is a non-empty string while the
     * container is built and would read as true (fail open).
     *
     * @param array<string, mixed> $config
     */
    private function assertLiteralBooleans(array $config): void
    {
        /** @var array<string, array<string, mixed>> $config */
        $switches = [
            'web_ui.enabled'                 => $config['web_ui']['enabled'] ?? null,
            'security.allow_unauthenticated' => $config['security']['allow_unauthenticated'] ?? null,
            'portability.web_ui'             => $config['portability']['web_ui'] ?? null,
            'portability.sealed_secrets'     => $config['portability']['sealed_secrets'] ?? null,
            'files.enabled'                  => $config['files']['enabled'] ?? null,
            'brand.enabled'                  => $config['brand']['enabled'] ?? null,
        ];
        foreach ($switches as $path => $value) {
            if (!is_bool($value)) {
                throw new InvalidConfigurationException(sprintf('nowo_instance_settings.%s must be a literal boolean (true or false); environment placeholders such as %%env(bool:...)%% are not supported for security switches.', $path));
            }
        }
    }
}
