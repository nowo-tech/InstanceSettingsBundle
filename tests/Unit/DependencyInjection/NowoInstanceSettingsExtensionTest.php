<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\InstanceSettingsBundle\Attribute\AsSettingsSection;
use Nowo\InstanceSettingsBundle\Command\PurgeFilesCommand;
use Nowo\InstanceSettingsBundle\Command\SeedCommand;
use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionMiddleware;
use Nowo\InstanceSettingsBundle\EventListener\InstanceSettingsAccessListener;
use Nowo\InstanceSettingsBundle\File\DoctrineSettingFileStore;
use Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore;
use Nowo\InstanceSettingsBundle\File\SettingFileController;
use Nowo\InstanceSettingsBundle\File\SettingFileStoreInterface;
use Nowo\InstanceSettingsBundle\Form\SettingImageType;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\PortabilityProviderInterface;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRouteLoader;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\AllowAllInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\ConfigurableInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Seed\SeedFileSeeder;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Filesystem\Filesystem;

final class NowoInstanceSettingsExtensionTest extends TestCase
{
    public function testDefaultServices(): void
    {
        $container = $this->load([], withSecurity: true);

        self::assertSame('nowo_instance_settings', (new NowoInstanceSettingsExtension())->getAlias());
        self::assertTrue($container->hasDefinition(SettingsController::class));
        self::assertFalse($container->getDefinition(SettingsController::class)->isAutoconfigured());
        self::assertTrue($container->getDefinition(SettingsController::class)->hasTag('controller.service_arguments'));
        self::assertTrue($container->hasDefinition(InstanceSettingsAccessListener::class));
        self::assertSame(ConfigurableInstanceSettingsAccessChecker::class, (string) $container->getAlias(InstanceSettingsAccessCheckerInterface::class));
        self::assertSame(['ROLE_ADMIN'], $container->getDefinition(ConfigurableInstanceSettingsAccessChecker::class)->getArgument(1));
        self::assertSame('/admin/settings', $container->getParameter('nowo_instance_settings.web_ui.path_prefix'));
        self::assertFalse($container->getParameter('nowo_instance_settings.security.allow_unauthenticated'));

        $autoconfigured = $container->getAutoconfiguredInstanceof();
        self::assertTrue($autoconfigured[SettingsSectionInterface::class]->hasTag(NowoInstanceSettingsBundle::SECTION_TAG));
        self::assertTrue($autoconfigured[PortabilityProviderInterface::class]->hasTag(NowoInstanceSettingsBundle::PORTABILITY_PROVIDER_TAG));

        $definition = new ChildDefinition('');
        $container->getAttributeAutoconfigurators()[AsSettingsSection::class][0]($definition, new AsSettingsSection(5));
        self::assertSame([['priority' => 5]], $definition->getTag(NowoInstanceSettingsBundle::SECTION_TAG));
    }

    public function testCustomCheckerAreaRolesAndDemoSwitch(): void
    {
        $custom = $this->load(['security' => ['access_checker' => 'app.checker']], withSecurity: true);
        self::assertSame('app.checker', (string) $custom->getAlias(InstanceSettingsAccessCheckerInterface::class));

        $areas = $this->load(['security' => ['area_roles' => ['mailer' => [' ROLE_SUPER_ADMIN '], '_portability' => ['ROLE_ADMIN']]]], withSecurity: true);
        self::assertSame(['mailer' => ['ROLE_SUPER_ADMIN'], '_portability' => ['ROLE_ADMIN']], $areas->getDefinition(ConfigurableInstanceSettingsAccessChecker::class)->getArgument(2));

        // Without an explicit _portability area: fine with a custom checker or without the import / export page.
        $this->load(['security' => ['area_roles' => ['mailer' => ['ROLE_X']], 'access_checker' => 'app.checker']], withSecurity: true);
        $this->load(['security' => ['area_roles' => ['mailer' => ['ROLE_X']]], 'web_ui' => ['enabled' => false]]);

        $demo = $this->load(['security' => ['allow_unauthenticated' => true, 'area_roles' => ['mailer' => ['ROLE_X']]]]);
        self::assertSame(AllowAllInstanceSettingsAccessChecker::class, (string) $demo->getAlias(InstanceSettingsAccessCheckerInterface::class));
    }

    public function testDisabledUiRegistersNoController(): void
    {
        $container = $this->load(['web_ui' => ['enabled' => false]]);

        self::assertFalse($container->hasDefinition(SettingsController::class));
        self::assertFalse($container->hasDefinition(InstanceSettingsAccessListener::class));
        self::assertSame(ConfigurableInstanceSettingsAccessChecker::class, (string) $container->getAlias(InstanceSettingsAccessCheckerInterface::class), 'kits authorise their own actions');
        self::assertTrue($container->hasDefinition(AccessGuard::class));
    }

    public function testUiWithoutSecurityFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires symfony/security-bundle');
        $this->load([]);
    }

    public function testPrependRegistersDoctrineMappingAndFormKitProfile(): void
    {
        $container = new ContainerBuilder();
        foreach (['doctrine', 'nowo_form_kit'] as $name) {
            $container->registerExtension($this->extension($name));
        }
        (new NowoInstanceSettingsExtension())->prepend($container);

        $mappings = $container->getExtensionConfig('doctrine')[0]['orm']['mappings'];
        self::assertSame('Nowo\InstanceSettingsBundle\Entity', $mappings['NowoInstanceSettingsBundle']['prefix']);
        self::assertArrayNotHasKey(NowoInstanceSettingsExtension::FILES_MAPPING, $mappings, 'files are opt-in: no file table by default');
        self::assertSame(NowoInstanceSettingsExtension::formKitProfile(), $container->getExtensionConfig('nowo_form_kit')[0]['profiles']['instance_settings']);

        // A host that defines the profile keeps its own.
        $host = new ContainerBuilder();
        $host->registerExtension($this->extension('nowo_form_kit'));
        $host->prependExtensionConfig('nowo_form_kit', ['profiles' => ['instance_settings' => ['translation_domain' => 'app']]]);
        (new NowoInstanceSettingsExtension())->prepend($host);
        self::assertCount(1, $host->getExtensionConfig('nowo_form_kit'));

        // Without the extensions nothing is prepended.
        $bare = new ContainerBuilder();
        (new NowoInstanceSettingsExtension())->prepend($bare);
        self::assertSame([], $bare->getExtensionConfig('doctrine'));
    }

    public function testPrependMapsTheFileEntityOnlyWithTheFlag(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->extension('doctrine'));
        $container->registerExtension(new NowoInstanceSettingsExtension());
        $container->loadFromExtension('nowo_instance_settings', ['files' => ['enabled' => true]]);
        $container->loadFromExtension('nowo_instance_settings', ['files' => ['path_prefix' => '/media']]);
        (new NowoInstanceSettingsExtension())->prepend($container);

        $mapping = $container->getExtensionConfig('doctrine')[0]['orm']['mappings'][NowoInstanceSettingsExtension::FILES_MAPPING];
        self::assertSame('Nowo\InstanceSettingsBundle\FileEntity', $mapping['prefix']);
        self::assertStringEndsWith('/FileEntity', $mapping['dir']);

        self::assertTrue(NowoInstanceSettingsExtension::filesEnabled([['files' => ['enabled' => true]], ['web_ui' => []]]));
        self::assertFalse(NowoInstanceSettingsExtension::filesEnabled([['files' => ['enabled' => true]], ['files' => ['enabled' => false]]]), 'the last value wins');
        self::assertFalse(NowoInstanceSettingsExtension::filesEnabled([['files' => ['enabled' => '%env(bool:FILES)%']]]), 'a placeholder never maps');
        self::assertFalse(NowoInstanceSettingsExtension::filesEnabled([['files' => 'x'], []]));
    }

    public function testFilesOffRegistersNoStoreRouteControllerOrPurge(): void
    {
        $container = $this->load([], withSecurity: true);

        self::assertFalse($container->getParameter('nowo_instance_settings.files.enabled'));
        self::assertFalse($container->has(SettingFileStoreInterface::class));
        self::assertFalse($container->hasDefinition(DoctrineSettingFileStore::class));
        self::assertFalse($container->hasDefinition(SettingFileController::class));
        self::assertFalse($container->hasDefinition(PurgeFilesCommand::class));
        self::assertNull($container->getDefinition(InstanceSettings::class)->getArgument(7));
        self::assertNull($container->getDefinition(ConfigPortability::class)->getArgument(10));
        self::assertFalse($container->getDefinition(SettingsSectionRegistry::class)->getArgument(2));
    }

    public function testImageFileServices(): void
    {
        $container = $this->load(['files' => ['enabled' => true, 'path_prefix' => '/media/settings', 'orphan_retention' => 7]], withSecurity: true);
        self::assertTrue($container->getDefinition(SettingsSectionRegistry::class)->getArgument(2));

        self::assertSame(DoctrineSettingFileStore::class, (string) $container->getAlias(SettingFileStoreInterface::class));
        $controller = $container->getDefinition(SettingFileController::class);
        self::assertTrue($controller->isPublic());
        self::assertFalse($controller->isAutoconfigured());
        self::assertSame(7, $container->getDefinition(PurgeFilesCommand::class)->getArgument(3));
        self::assertSame('/media/settings', $container->getParameter('nowo_instance_settings.files.path_prefix'));
        self::assertSame('/media/settings', $container->getDefinition(InstanceSettingsRouteLoader::class)->getArgument(5));
        self::assertTrue($container->hasDefinition(SettingImageType::class));
    }

    public function testFilesystemStoreAndSeedServices(): void
    {
        $container = $this->load(['brand' => ['enabled' => false], 'files' => ['enabled' => true, 'store' => 'filesystem', 'directory' => '/srv/files'], 'seed' => ['file' => '/srv/app/seed.yaml', 'base_dir' => '/srv/app']], withSecurity: true);

        self::assertSame(FilesystemSettingFileStore::class, (string) $container->getAlias(SettingFileStoreInterface::class));
        self::assertSame('/srv/files', $container->getDefinition(FilesystemSettingFileStore::class)->getArgument(0));
        self::assertTrue($container->getDefinition(FilesystemSettingFileStore::class)->hasTag('kernel.reset'));
        self::assertTrue($container->getDefinition(FileStoreTransactionMiddleware::class)->hasTag('doctrine.middleware'));
        $seeder = $container->getDefinition(SeedFileSeeder::class);
        self::assertTrue($seeder->isPublic());
        self::assertSame(['/srv/app', '/srv/app/seed.yaml'], [$seeder->getArgument(3), $seeder->getArgument(4)]);
        self::assertSame(SettingFileStoreInterface::class, (string) $seeder->getArgument(5));
        self::assertTrue($container->getDefinition(SeedCommand::class)->hasTag('console.command'));

        $off = $this->load([], withSecurity: true);
        self::assertNull($off->getDefinition(SeedFileSeeder::class)->getArgument(5));
        self::assertNull($off->getDefinition(SeedFileSeeder::class)->getArgument(4));
        self::assertFalse($off->hasDefinition(FilesystemSettingFileStore::class));
    }

    public function testTheFilesystemDirectoryMayNotBeUnderThePublicWebRootWhateverItsSpelling(): void
    {
        $project = sys_get_temp_dir() . '/nowo-is-ext-' . bin2hex(random_bytes(6));
        mkdir($project . '/public', 0o755, true);
        mkdir($project . '/src', 0o755, true);
        mkdir($project . '/web', 0o755, true);
        symlink($project . '/public', $project . '/linked');
        try {
            foreach (['%kernel.project_dir%/src/../public/files', $project . '//public/files', $project . '/./public/files', $project . '/linked/sub', $project . '/web/files'] as $directory) {
                $container = new ContainerBuilder();
                $container->setParameter('kernel.project_dir', $project);
                try {
                    (new NowoInstanceSettingsExtension())->load([['web_ui' => ['enabled' => false], 'files' => ['enabled' => true, 'store' => 'filesystem', 'directory' => $directory], 'brand' => ['public_dir' => $project . '/web']]], $container);
                    self::fail('Expected the refusal of ' . $directory);
                } catch (LogicException $e) {
                    self::assertStringContainsString('under the public web root', $e->getMessage());
                }
            }
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', $project);
            (new NowoInstanceSettingsExtension())->load([['web_ui' => ['enabled' => false], 'files' => ['enabled' => true, 'store' => 'filesystem', 'directory' => '%kernel.project_dir%/config/instance-files']]], $container);
            self::assertSame(['%kernel.project_dir%/public', '%kernel.project_dir%/public'], $container->getDefinition(FilesystemSettingFileStore::class)->getArgument(1));
        } finally {
            (new Filesystem())->remove($project);
        }
    }

    public function testALiteralBlankPathIsRefusedButAnEnvPlaceholderWaitsForRunTime(): void
    {
        foreach ([['files' => ['directory' => '']], ['seed' => ['base_dir' => '']]] as $config) {
            try {
                $this->load($config, withSecurity: true);
                self::fail('Expected InvalidConfigurationException');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('must be a local directory path, got ""', $e->getMessage());
            }
        }

        // 1.7.1: an %env(...)% value (here under the public root) is not judged at build time; the store checks it.
        $project = sys_get_temp_dir() . '/nowo-is-ext-env-' . bin2hex(random_bytes(6));
        mkdir($project . '/public', 0o755, true);
        try {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', $project);
            $files = $this->env($container, 'IS_FILES');
            $base  = $this->env($container, 'IS_BASE');
            (new NowoInstanceSettingsExtension())->load([['web_ui' => ['enabled' => false], 'files' => ['enabled' => true, 'store' => 'filesystem', 'directory' => $files], 'seed' => ['base_dir' => $base]]], $container);
            self::assertSame($files, $container->getDefinition(FilesystemSettingFileStore::class)->getArgument(0));
            self::assertSame($base, $container->getDefinition(SeedFileSeeder::class)->getArgument(3));

            // A literal directory is still checked against the literal roots when brand.public_dir is an env value.
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', $project);
            $public = $this->env($container, 'IS_PUBLIC');
            try {
                (new NowoInstanceSettingsExtension())->load([['web_ui' => ['enabled' => false], 'files' => ['enabled' => true, 'store' => 'filesystem', 'directory' => '%kernel.project_dir%/public/files'], 'brand' => ['public_dir' => $public]]], $container);
                self::fail('Expected the refusal.');
            } catch (LogicException $e) {
                self::assertStringContainsString('under the public web root', $e->getMessage());
            }
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', $project);
            (new NowoInstanceSettingsExtension())->load([['web_ui' => ['enabled' => false], 'files' => ['enabled' => true, 'store' => 'filesystem', 'directory' => '%kernel.project_dir%/config/files'], 'brand' => ['public_dir' => $this->env($container, 'IS_PUBLIC')]]], $container);
            self::assertTrue($container->hasDefinition(FilesystemSettingFileStore::class));
        } finally {
            (new Filesystem())->remove($project);
        }
    }

    /**
     * The container's placeholder of `%env(<name>)%`.
     */
    private function env(ContainerBuilder $container, string $name): string
    {
        $placeholder = $container->getParameterBag()->get('env(' . $name . ')');
        self::assertIsString($placeholder);

        return $placeholder;
    }

    public function testFilePrefixMustBeOutsideTheAdminPrefix(): void
    {
        foreach ([
            ['files' => ['path_prefix' => '/admin/settings']],
            ['files' => ['path_prefix' => '/admin/settings/files/']],
            ['files' => ['path_prefix' => '/admin'], 'web_ui' => ['path_prefix' => '/admin/settings']],
        ] as $config) {
            try {
                $this->load($config, withSecurity: true);
                self::fail('Expected InvalidConfigurationException');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('must be outside web_ui.path_prefix', $e->getMessage());
            }
        }
        $this->load(['files' => ['path_prefix' => '/admin/settingsfiles']], withSecurity: true);
        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config, bool $withSecurity = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        if ($withSecurity) {
            $container->registerExtension($this->extension('security'));
        }
        (new NowoInstanceSettingsExtension())->load([$config], $container);

        return $container;
    }

    private function extension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }
}
