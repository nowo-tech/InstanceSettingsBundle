<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection;

use LogicException;
use Nowo\DoctrineEncryptBundle\Util\EncryptUtil;
use Nowo\InstanceSettingsBundle\Attribute\AsSettingsSection;
use Nowo\InstanceSettingsBundle\Command\ExportSettingsCommand;
use Nowo\InstanceSettingsBundle\Command\ImportSettingsCommand;
use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\Doctrine\TablePrefixListener;
use Nowo\InstanceSettingsBundle\EventListener\InstanceSettingsAccessListener;
use Nowo\InstanceSettingsBundle\Form\PortabilityExportType;
use Nowo\InstanceSettingsBundle\Form\PortabilityImportType;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\JsonUploadReader;
use Nowo\InstanceSettingsBundle\Portability\PortabilityProviderInterface;
use Nowo\InstanceSettingsBundle\Repository\DoctrineSettingValueRepository;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRouteLoader;
use Nowo\InstanceSettingsBundle\Secret\DoctrineEncryptSecretSealer;
use Nowo\InstanceSettingsBundle\Secret\SecretSealerInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\AllowAllInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\ConfigurableInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Twig\InstanceSettingsTwigExtension;
use Nowo\InstanceSettingsBundle\Ui\SettingsPageFactory;
use Nowo\InstanceSettingsBundle\Ui\SettingsPages;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;

use function array_key_exists;
use function array_keys;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;

/**
 * Loads `nowo_instance_settings`: the settings store, sections registry, portability, CLI, and (when enabled) the
 * admin UI with its access checker, route loader and FormKit profile.
 */
final class NowoInstanceSettingsExtension extends Extension implements PrependExtensionInterface
{
    /**
     * `nowo_instance_settings`.
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Prepends the Doctrine mapping of the bundle entity and the FormKit profile of the bundle forms.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'NowoInstanceSettingsBundle' => [
                            'type'      => 'attribute',
                            'dir'       => __DIR__ . '/../Entity',
                            'prefix'    => 'Nowo\InstanceSettingsBundle\Entity',
                            'alias'     => 'NowoInstanceSettings',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
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

        /** @var array{doctrine: array{table_prefix: string, entity_manager: string|null}, web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>}, security: array{access_roles: list<string>, area_roles: array<string, list<string>>, access_checker: string|null, allow_unauthenticated: bool}, portability: array{web_ui: bool, schema: string, max_upload_bytes: int, sealed_secrets: bool}} $config */
        $webUi       = $config['web_ui']['enabled'];
        $portability = $config['portability'];

        if ($webUi && !$config['security']['allow_unauthenticated'] && !$this->hasSecurityBundle($container)) {
            throw new LogicException('NowoInstanceSettingsBundle: the admin UI requires symfony/security-bundle (or security.allow_unauthenticated: true in dev / demo only).');
        }

        $this->assertPortabilityArea($config);

        $container->setParameter('nowo_instance_settings.doctrine.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter('nowo_instance_settings.web_ui.enabled', $webUi);
        $container->setParameter('nowo_instance_settings.web_ui.path_prefix', $config['web_ui']['path_prefix']);
        $container->setParameter('nowo_instance_settings.web_ui.layout_template', $config['web_ui']['layout_template']);
        $container->setParameter('nowo_instance_settings.web_ui.templates', $config['web_ui']['templates']);
        $container->setParameter('nowo_instance_settings.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
        $container->setParameter('nowo_instance_settings.portability.schema', $portability['schema']);

        $container->registerForAutoconfiguration(SettingsSectionInterface::class)->addTag(NowoInstanceSettingsBundle::SECTION_TAG);
        $container->registerForAutoconfiguration(PortabilityProviderInterface::class)->addTag(NowoInstanceSettingsBundle::PORTABILITY_PROVIDER_TAG);
        $container->registerAttributeForAutoconfiguration(AsSettingsSection::class, static function (ChildDefinition $definition, AsSettingsSection $attribute): void {
            $definition->addTag(NowoInstanceSettingsBundle::SECTION_TAG, ['priority' => $attribute->priority]);
        });

        $this->registerStore($container, $config['doctrine']);
        $this->registerPortability($container, $portability);
        $this->registerWebUi($container, $config);
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
     * @param array{table_prefix: string, entity_manager: string|null} $doctrine
     */
    private function registerStore(ContainerBuilder $container, array $doctrine): void
    {
        $container->setDefinition(SettingsSectionRegistry::class, new Definition(SettingsSectionRegistry::class, [
            new TaggedIteratorArgument(NowoInstanceSettingsBundle::SECTION_TAG),
        ]));

        $container->setDefinition(DoctrineSettingValueRepository::class, new Definition(DoctrineSettingValueRepository::class, [
            new Reference('doctrine'),
            $doctrine['entity_manager'],
        ]));
        $container->setAlias(SettingValueRepositoryInterface::class, DoctrineSettingValueRepository::class);

        $container->setDefinition(TablePrefixListener::class, (new Definition(TablePrefixListener::class, [$doctrine['table_prefix']]))
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));

        $container->setDefinition(ValueNormalizer::class, new Definition(ValueNormalizer::class, [
            new Reference('validator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));

        $container->setDefinition(InstanceSettings::class, (new Definition(InstanceSettings::class, [
            new Reference(SettingValueRepositoryInterface::class),
            new Reference(SettingsSectionRegistry::class),
            new Reference(ValueNormalizer::class),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('kernel.reset', ['method' => 'reset']));
        $container->setAlias(InstanceSettingsInterface::class, InstanceSettings::class)->setPublic(true);

        $container->setDefinition(InstanceSettingsTwigExtension::class, (new Definition(InstanceSettingsTwigExtension::class, [
            new Reference(InstanceSettingsInterface::class),
        ]))->addTag('twig.extension'));
    }

    /**
     * @param array{web_ui: bool, schema: string, max_upload_bytes: int, sealed_secrets: bool} $portability
     */
    private function registerPortability(ContainerBuilder $container, array $portability): void
    {
        $container->setDefinition(DoctrineEncryptSecretSealer::class, new Definition(DoctrineEncryptSecretSealer::class, [
            new Reference(EncryptUtil::class),
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
            $container->setAlias(InstanceSettingsAccessCheckerInterface::class, $customId);
        } elseif ($security['allow_unauthenticated']) {
            $container->setDefinition(AllowAllInstanceSettingsAccessChecker::class, new Definition(AllowAllInstanceSettingsAccessChecker::class));
            $container->setAlias(InstanceSettingsAccessCheckerInterface::class, AllowAllInstanceSettingsAccessChecker::class);
        } else {
            $areaRoles = [];
            foreach ($security['area_roles'] as $area => $roles) {
                $areaRoles[(string) $area] = Configuration::roles($roles);
            }
            $container->setDefinition(ConfigurableInstanceSettingsAccessChecker::class, new Definition(ConfigurableInstanceSettingsAccessChecker::class, [
                new Reference('security.authorization_checker'),
                Configuration::roles($security['access_roles']),
                $areaRoles,
            ]));
            $container->setAlias(InstanceSettingsAccessCheckerInterface::class, ConfigurableInstanceSettingsAccessChecker::class);
        }

        $container->setDefinition(AccessGuard::class, new Definition(AccessGuard::class, [
            new Reference(InstanceSettingsAccessCheckerInterface::class),
            $security['allow_unauthenticated'],
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('security.firewall.map', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
    }

    /**
     * @param array{web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>}, security: array{access_roles: list<string>, area_roles: array<string, list<string>>, access_checker: string|null, allow_unauthenticated: bool}, portability: array{web_ui: bool, schema: string, max_upload_bytes: int, sealed_secrets: bool}} $config
     */
    private function registerWebUi(ContainerBuilder $container, array $config): void
    {
        $webUi              = $config['web_ui'];
        $portabilityEnabled = $config['portability']['web_ui'];

        $container->setDefinition(InstanceSettingsRouteLoader::class, (new Definition(InstanceSettingsRouteLoader::class, [
            $webUi['enabled'],
            $webUi['path_prefix'],
            $portabilityEnabled,
        ]))->addTag('routing.loader'));

        if (!$webUi['enabled']) {
            return;
        }

        $this->registerAccess($container, $config['security']);

        foreach ([SettingsSectionType::class, PortabilityImportType::class, PortabilityExportType::class] as $type) {
            $container->setDefinition($type, (new Definition($type))->addTag('form.type'));
        }

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
        ];
        foreach ($switches as $path => $value) {
            if (!is_bool($value)) {
                throw new InvalidConfigurationException(sprintf('nowo_instance_settings.%s must be a literal boolean (true or false); environment placeholders such as %%env(bool:...)%% are not supported for security switches.', $path));
            }
        }
    }
}
