<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;

/**
 * The bundle's private entity manager: shares the source manager's connection, configuration and events, is replaced
 * when closed or when the connection changed, and never touches the source manager.
 */
final class SettingsEntityManagerTest extends KernelTestCase
{
    public function testPrivateManagerLifecycle(): void
    {
        $kernel   = $this->bootKernel();
        $registry = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $source = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $source);

        $holder  = new SettingsEntityManager($registry);
        $private = $holder->get();
        self::assertNotSame($source, $private);
        self::assertSame($source, $holder->source());
        self::assertSame($source->getConnection(), $private->getConnection());
        self::assertSame($source->getConfiguration(), $private->getConfiguration());
        self::assertSame($source->getEventManager(), $private->getEventManager());
        self::assertSame($private, $holder->get(), 'kept while open');

        $private->close();
        $fresh = $holder->get();
        self::assertNotSame($private, $fresh, 'a closed private manager is replaced');
        self::assertTrue($source->isOpen());

        $holder->clear();
        $holder->reset();
        self::assertNotSame($fresh, $holder->get(), 'reset drops it');

        // Named source manager (doctrine.entity_manager).
        self::assertSame($source, (new SettingsEntityManager($registry, 'default'))->source());
    }

    public function testAnotherConnectionGivesAnotherPrivateManager(): void
    {
        $kernel = $this->bootKernel();
        $real   = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $real);
        $first = $real->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $first);

        $other = $this->createStub(EntityManagerInterface::class);
        $other->method('getConnection')->willReturn(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $other->method('getConfiguration')->willReturn($first->getConfiguration());
        $other->method('getEventManager')->willReturn($first->getEventManager());

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnOnConsecutiveCalls($first, $other);

        $holder  = new SettingsEntityManager($registry);
        $private = $holder->get();
        $next    = $holder->get();
        self::assertNotSame($private, $next);
        self::assertNotSame($first->getConnection(), $next->getConnection());
    }

    public function testAnUnmappedOrNonOrmManagerIsRefused(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Entity manager "settings" is not a Doctrine ORM entity manager that maps');
        (new SettingsEntityManager($registry, 'settings'))->get();
    }
}
