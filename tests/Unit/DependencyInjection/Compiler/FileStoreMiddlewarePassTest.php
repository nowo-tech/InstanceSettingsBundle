<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\FileStoreMiddlewarePass;
use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The middleware follows only the connection of the bundle's entity manager (audit JG-L7).
 */
final class FileStoreMiddlewarePassTest extends TestCase
{
    public function testTheBundleEntityManagersConnectionIsTagged(): void
    {
        $container = $this->container();
        $container->setParameter(FileStoreMiddlewarePass::ENTITY_MANAGER_PARAMETER, 'settings');
        $container->setDefinition('doctrine.orm.settings_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.archive_connection')]));
        $container->setDefinition('doctrine.orm.default_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.default_connection')]));

        self::assertSame([['connection' => 'archive']], $this->tags($container));
    }

    public function testTheDefaultEntityManagerAndConnectionAreTheFallbacks(): void
    {
        $container = $this->container();
        $container->setParameter(FileStoreMiddlewarePass::ENTITY_MANAGER_PARAMETER, null);
        $container->setParameter('doctrine.default_entity_manager', 'main');
        $container->setDefinition('doctrine.orm.main_entity_manager', new Definition('EM', [new Reference('doctrine.dbal.primary_connection')]));
        self::assertSame([['connection' => 'primary']], $this->tags($container));

        $container = $this->container();
        $container->setParameter('doctrine.default_connection', 'fallback');
        self::assertSame([['connection' => 'fallback']], $this->tags($container), 'no entity manager definition');

        self::assertSame([['connection' => 'default']], $this->tags($this->container()));

        $empty = new ContainerBuilder();
        (new FileStoreMiddlewarePass())->process($empty);
        self::assertFalse($empty->hasDefinition(FileStoreTransactionMiddleware::class));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(FileStoreTransactionMiddleware::class, (new Definition(FileStoreTransactionMiddleware::class))->addTag('doctrine.middleware'));

        return $container;
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function tags(ContainerBuilder $container): array
    {
        (new FileStoreMiddlewarePass())->process($container);

        return $container->getDefinition(FileStoreTransactionMiddleware::class)->getTag('doctrine.middleware');
    }
}
