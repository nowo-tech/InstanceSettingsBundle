<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\EntityManagerMappingPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\FileStoreMiddlewarePass;
use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use Nowo\InstanceSettingsBundle\Doctrine\ExcludedEntityNamespaceDriver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_map;
use function array_values;
use function is_string;

final class EntityManagerMappingPassTest extends TestCase
{
    public function testTheEntitiesStayOnTheDedicatedManagerOnly(): void
    {
        $container = $this->container('settings', ['default', 'settings']);
        $this->chain($container, 'default', ['App\Entity', EntityManagerMappingPass::ENTITY_NAMESPACE, EntityManagerMappingPass::FILE_ENTITY_NAMESPACE]);
        $this->chain($container, 'settings', [EntityManagerMappingPass::ENTITY_NAMESPACE, EntityManagerMappingPass::FILE_ENTITY_NAMESPACE]);
        $container->getDefinition('doctrine.orm.default_metadata_driver')->addMethodCall('setDefaultDriver', [new Reference('x')]);

        (new EntityManagerMappingPass())->process($container);

        // Other managers: the host's drivers for the namespaces are gone; drivers that map nothing come first, before the
        // host's drivers and before any default driver (DoctrineBundle 3.4 auto_discover_entities).
        $excluded = ['addDriver:' . EntityManagerMappingPass::ENTITY_NAMESPACE, 'addDriver:' . EntityManagerMappingPass::FILE_ENTITY_NAMESPACE];
        self::assertSame([...$excluded, 'addDriver:App\Entity', 'setDefaultDriver:'], $this->calls($container, 'default'));
        foreach ([0, 1] as $index) {
            $driver = $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls()[$index][1][0];
            self::assertInstanceOf(Definition::class, $driver);
            self::assertSame(ExcludedEntityNamespaceDriver::class, $driver->getClass());
        }
        self::assertSame($excluded, $this->calls($container, 'settings'));
        self::assertEquals(new Reference('driver'), $container->getDefinition('doctrine.orm.settings_metadata_driver')->getMethodCalls()[0][1][0], 'the configured manager keeps its own drivers');

        (new EntityManagerMappingPass())->process($container);
        self::assertSame([...$excluded, 'addDriver:App\Entity', 'setDefaultDriver:'], $this->calls($container, 'default'), 'idempotent');
        self::assertSame($excluded, $this->calls($container, 'settings'), 'idempotent');
    }

    public function testNothingWithoutADedicatedManager(): void
    {
        $container = $this->container(null, ['default']);
        $this->chain($container, 'default', [EntityManagerMappingPass::ENTITY_NAMESPACE]);
        (new EntityManagerMappingPass())->process($container);
        self::assertCount(1, $this->calls($container, 'default'));

        $bare = new ContainerBuilder();
        (new EntityManagerMappingPass())->process($bare);
        $bare->setParameter(NowoInstanceSettingsExtension::DEDICATED_MAPPING_PARAMETER, true);
        (new EntityManagerMappingPass())->process($bare);
        self::assertFalse($bare->hasParameter('doctrine.entity_managers'));
    }

    public function testAnUnknownManagerFails(): void
    {
        $container = $this->container('settings', ['default']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_instance_settings.doctrine.entity_manager is "settings", but no such Doctrine entity manager is configured (known: "default")');
        (new EntityManagerMappingPass())->process($container);
    }

    public function testTheCheckOnlyPassLeavesTheChainsAlone(): void
    {
        $container = $this->container('settings', ['default', 'settings']);
        $this->chain($container, 'default', [EntityManagerMappingPass::ENTITY_NAMESPACE]);
        (new EntityManagerMappingPass(true))->process($container);
        self::assertCount(1, $this->calls($container, 'default'));

        $this->expectException(InvalidConfigurationException::class);
        (new EntityManagerMappingPass(true))->process($this->container('missing', ['default']));
    }

    public function testAManagerThatDoesNotMapTheEntitiesFails(): void
    {
        $container = $this->container('settings', ['default', 'settings', 'other']);
        $this->chain($container, 'settings', ['App\Entity']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The Doctrine entity manager "settings" (nowo_instance_settings.doctrine.entity_manager) does not map Nowo\InstanceSettingsBundle\Entity.');
        (new EntityManagerMappingPass())->process($container);
    }

    /**
     * @param list<string> $managers
     */
    private function container(?string $name, array $managers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoInstanceSettingsExtension::DEDICATED_MAPPING_PARAMETER, true);
        $container->setParameter(FileStoreMiddlewarePass::ENTITY_MANAGER_PARAMETER, $name);
        $map = [];
        foreach ($managers as $manager) {
            $map[$manager] = 'doctrine.orm.' . $manager . '_entity_manager';
        }
        $container->setParameter('doctrine.entity_managers', $map);

        return $container;
    }

    /**
     * @param list<string> $namespaces
     */
    private function chain(ContainerBuilder $container, string $manager, array $namespaces): void
    {
        $chain = new Definition();
        foreach ($namespaces as $namespace) {
            $chain->addMethodCall('addDriver', [new Reference('driver'), $namespace]);
        }
        $container->setDefinition('doctrine.orm.' . $manager . '_metadata_driver', $chain);
    }

    /**
     * @return list<string>
     */
    private function calls(ContainerBuilder $container, string $manager): array
    {
        return array_values(array_map(static fn (array $call): string => $call[0] . ':' . (isset($call[1][1]) && is_string($call[1][1]) ? $call[1][1] : ''), $container->getDefinition('doctrine.orm.' . $manager . '_metadata_driver')->getMethodCalls()));
    }
}
