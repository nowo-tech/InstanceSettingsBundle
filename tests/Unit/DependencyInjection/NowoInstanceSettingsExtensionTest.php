<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\InstanceSettingsBundle\Attribute\AsSettingsSection;
use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use Nowo\InstanceSettingsBundle\EventListener\InstanceSettingsAccessListener;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Portability\PortabilityProviderInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Security\AllowAllInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\ConfigurableInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

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
        self::assertFalse($container->hasAlias(InstanceSettingsAccessCheckerInterface::class));
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

        $mapping = $container->getExtensionConfig('doctrine')[0]['orm']['mappings']['NowoInstanceSettingsBundle'];
        self::assertSame('Nowo\InstanceSettingsBundle\Entity', $mapping['prefix']);
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
