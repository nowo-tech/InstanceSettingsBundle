<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\Expiry\DoctrineSecretExpiryStore;
use Nowo\InstanceSettingsBundle\Expiry\ExpiryDate;
use Nowo\InstanceSettingsBundle\ExpiryEntity\SecretExpiry;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\RacingConnectionMiddleware;

/**
 * Audit I4 (1.18.1): two editors saving the first expiry date of the same secret at the same moment. The store reads
 * "no date yet", the other request inserts and commits, then this one inserts: the unique key refuses it. Before 1.18.1
 * the DBAL exception escaped (HTTP 500); now the losing insert is rolled back to its own savepoint and the date is
 * written over the other one (last writer wins, as for every other setting), with or without an outer transaction.
 */
final class SecretExpiryStoreRaceTest extends KernelTestCase
{
    private const TABLE = 'instance_setting_secret_expiry';

    private RacingConnectionMiddleware $middleware;

    private Connection $connection;

    private DoctrineSecretExpiryStore $store;

    protected function setUp(): void
    {
        $kernel   = $this->bootKernel(['expiring_secrets' => ['enabled' => true, 'timezone' => 'UTC']]);
        $registry = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $source = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $source);

        $this->middleware = new RacingConnectionMiddleware(self::TABLE);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([$this->middleware]));
        $manager          = new EntityManager($this->connection, $source->getConfiguration());
        (new SchemaTool($manager))->createSchema([$manager->getClassMetadata(SecretExpiry::class)]);

        $holder = $this->createStub(ManagerRegistry::class);
        $holder->method('getManagerForClass')->willReturn($manager);
        $this->store = new DoctrineSecretExpiryStore(new SettingsEntityManager($holder));
    }

    public function testAConcurrentFirstDateIsOverwrittenInsteadOfFailing(): void
    {
        $this->raceWith('2030-01-01', 30);

        $this->store->write('providers', ['api_key' => new DateTimeImmutable('2031-06-30')], new DateTimeImmutable('2026-11-15 10:00:00'));

        self::assertSame(1, $this->middleware->raced);
        self::assertSame([['2031-06-30', null]], $this->rows(), 'one row, the later date, its thresholds reset');
    }

    public function testTheSameConcurrentDateKeepsTheAnnouncedThreshold(): void
    {
        $this->raceWith('2031-06-30', 30);

        $this->store->write('providers', ['api_key' => new DateTimeImmutable('2031-06-30')], new DateTimeImmutable('2026-11-15 10:00:00'));

        self::assertSame(1, $this->middleware->raced);
        self::assertSame([['2031-06-30', 30]], $this->rows(), 'an equal date is not a change');
    }

    public function testInsideTheSettingsTransactionOnlyTheLosingInsertIsRolledBack(): void
    {
        $this->connection->beginTransaction();
        $this->connection->insert(self::TABLE, ['section' => 'providers', 'name' => 'other_key', 'expires_on' => '2032-01-01', 'updated_at' => '2026-11-15 09:00:00']);
        $this->raceWith('2030-01-01', 7);

        $this->store->write('providers', ['api_key' => new DateTimeImmutable('2031-06-30')], new DateTimeImmutable('2026-11-15 10:00:00'));
        $this->connection->commit();

        self::assertSame(1, $this->middleware->raced);
        self::assertSame([['2031-06-30', null], ['2032-01-01', null]], $this->rows(), 'the outer transaction commits its own row too');
    }

    /**
     * Arms the "other request": it commits the first date of providers.api_key before this one inserts.
     */
    private function raceWith(string $date, int $threshold): void
    {
        $this->middleware->race = static function (DriverConnection $other) use ($date, $threshold): void {
            $other->exec("INSERT INTO instance_setting_secret_expiry (section, name, expires_on, notified_threshold, notified_at, updated_at) VALUES ('providers', 'api_key', '" . $date . "', " . $threshold . ", '2026-11-15 09:00:00', '2026-11-15 09:00:00')");
        };
    }

    /**
     * @return list<array{0: string, 1: int|null}>
     */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->store->findAll() as $record) {
            $rows[] = [ExpiryDate::format($record->expiresOn), $record->notifiedThreshold];
        }

        return $rows;
    }
}
