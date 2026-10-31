<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\DoctrineSettingValueRepository;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Throwable;

/**
 * Real Doctrine storage: one query for every read of a request, refreshed rows (worker-safe), transactions, closed
 * entity manager recovery, prefixed table.
 */
final class DoctrineSettingValueRepositoryTest extends KernelTestCase
{
    public function testStorageAndPrefix(): void
    {
        $kernel    = $this->bootKernel(['doctrine' => ['table_prefix' => 'app_']]);
        $container = $this->container($kernel);
        $settings  = $this->settings($kernel);
        /** @var SettingValueRepositoryInterface $repository */
        $repository = $container->get(SettingValueRepositoryInterface::class);

        $settings->save('mailer', ['from' => 'ops@example.test', 'dsn' => 'smtp://REDACTED-TEST']);
        $settings->save('general', ['page_size' => 30]);

        self::assertCount(3, $repository->findAll());
        self::assertSame(['dsn', 'from'], array_map(static fn (SettingValue $r): string => $r->getName(), $repository->findBySection('mailer')));
        $connection = $this->connection($kernel);
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_instance_setting_value'));

        // Another process changes a row: the next request (reset) sees it.
        $connection->executeStatement("UPDATE app_instance_setting_value SET value = '45' WHERE name = 'page_size'");
        $settings->reset();
        self::assertSame(45, $settings->getInt('general.page_size'));

        self::assertSame('done', $repository->transactional(static fn (): string => 'done'));
    }

    public function testClosedManagerIsReset(): void
    {
        $kernel    = $this->bootKernel();
        $container = $this->container($kernel);
        /** @var ManagerRegistry $registry */
        $registry   = $container->get('doctrine');
        $repository = new DoctrineSettingValueRepository($registry);

        $row = new SettingValue('mailer', 'from', new DateTimeImmutable());
        $repository->apply([$row->storeValue('a@example.test', new DateTimeImmutable())], []);

        $duplicate = (new SettingValue('mailer', 'from', new DateTimeImmutable()))->storeValue('b@example.test', new DateTimeImmutable());
        try {
            $repository->apply([$duplicate], []);
            self::fail('Expected a unique constraint violation');
        } catch (Throwable) {
            $this->addToAssertionCount(1);
        }

        // The closed manager was reset: the repository works again (also when closed outside the repository).
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->close();
        self::assertCount(1, $repository->findAll());
        $repository->apply([], $repository->findAll());
        self::assertSame([], $repository->findAll());

        $failing = new DoctrineSettingValueRepository($registry);
        try {
            $failing->apply([(new SettingValue('x', 'y', new DateTimeImmutable()))->storeValue(1, new DateTimeImmutable()), (new SettingValue('x', 'y', new DateTimeImmutable()))->storeValue(2, new DateTimeImmutable())], []);
        } catch (Throwable) {
        }
        self::assertSame([], $failing->findBySection('x'));
    }

    public function testNonOrmManagerIsRefused(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Entity manager "default" is not a Doctrine ORM entity manager.');
        (new DoctrineSettingValueRepository($registry))->findAll();
    }
}
