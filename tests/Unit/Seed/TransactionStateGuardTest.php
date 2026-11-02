<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Seed;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\InstanceSettingsBundle\Seed\TransactionStateGuard;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class TransactionStateGuardTest extends TestCase
{
    public function testNoOpenTransactionPasses(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(0);
        $connection->expects(self::never())->method('getNativeConnection');

        TransactionStateGuard::assertConsistent($connection);
        $this->addToAssertionCount(1);
    }

    public function testRealTransactionPasses(): void
    {
        TransactionStateGuard::assertConsistent($this->connection(1, $this->pdo(true)));
        $this->addToAssertionCount(1);
    }

    public function testPhantomTransactionThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Doctrine reports 1 open transaction level(s) but the database connection has none');
        TransactionStateGuard::assertConsistent($this->connection(1, $this->pdo(false)));
    }

    public function testUnknownNativeConnectionsAreBestEffort(): void
    {
        TransactionStateGuard::assertConsistent($this->connection(1, new stdClass()));
        TransactionStateGuard::assertConsistent($this->connection(1, 'resource'));

        $failing = $this->createMock(Connection::class);
        $failing->method('getTransactionNestingLevel')->willReturn(1);
        $failing->method('getNativeConnection')->willThrowException(new RuntimeException('closed'));
        TransactionStateGuard::assertConsistent($failing);
        $this->addToAssertionCount(3);
    }

    public function testNonOrmManagerIsIgnored(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->with(null)->willReturn($this->createMock(ObjectManager::class));

        (new TransactionStateGuard($doctrine))->check();
        $this->addToAssertionCount(1);
    }

    private function connection(int $level, mixed $native): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn($level);
        $connection->method('getNativeConnection')->willReturn($native);

        return $connection;
    }

    private function pdo(bool $inTransaction): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn($inTransaction);

        return $pdo;
    }
}
