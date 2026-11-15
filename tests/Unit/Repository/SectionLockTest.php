<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Result;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\SectionLock;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 1.19 (Beacon spec 239): the section write lock per platform.
 */
final class SectionLockTest extends TestCase
{
    private const COLUMNS = ['section' => 'section', 'name' => 'name', 'updated_at' => 'updated_at'];

    /**
     * @return iterable<string, array{0: AbstractPlatform, 1: list<string>, 2: bool}>
     */
    public static function platforms(): iterable
    {
        yield 'MySQL' => [new MySQL80Platform(), ['INSERT INTO t (section, name, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id'], false];
        yield 'MariaDB' => [new MariaDBPlatform(), ['INSERT INTO t (section, name, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id'], false];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), ['INSERT INTO t (section, name, updated_at) VALUES (?, ?, ?) ON CONFLICT (section, name) DO NOTHING'], true];
        yield 'SQLite' => [new SQLitePlatform(), ['INSERT INTO t (section, name, updated_at) VALUES (?, ?, ?) ON CONFLICT (section, name) DO NOTHING'], false];
        yield 'unsupported' => [new SQLServerPlatform(), [], false];
    }

    /**
     * @param list<string> $statements
     */
    #[DataProvider('platforms')]
    public function testTheLockStatementsOfEachPlatform(AbstractPlatform $platform, array $statements, bool $select): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $seen = [];
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $values) use (&$seen): int {
            self::assertSame([SectionLock::SECTION, 'general'], [$values[0], $values[1]]);
            $seen[] = $sql;

            return 1;
        });
        $connection->expects($select ? self::once() : self::never())->method('executeQuery')
            ->with('SELECT id FROM t WHERE section = ? AND name = ? FOR UPDATE', [SectionLock::SECTION, 'general'])
            ->willReturn($this->createStub(Result::class));

        SectionLock::acquire($connection, 't', self::COLUMNS, 'general', new DateTimeImmutable());

        self::assertSame($statements, $seen);
    }

    public function testANewSentinelGetsItsIdFromTheSequenceWhenGiven(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::once())->method('executeStatement')
            ->with('INSERT INTO t (section, name, updated_at, uid) VALUES (?, ?, ?, ?) ON CONFLICT (section, name) DO NOTHING', self::callback(static fn (array $values): bool => $values[3] === 42))
            ->willReturn(1);
        $connection->method('executeQuery')->willReturn($this->createStub(Result::class));

        SectionLock::acquire($connection, 't', self::COLUMNS + ['id' => 'uid'], 'general', new DateTimeImmutable(), 42);
    }

    public function testOutsideATransactionTheLockIsRefused(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);

        $this->expectException(LogicException::class);
        SectionLock::acquire($connection, 't', self::COLUMNS, 'general', new DateTimeImmutable());
    }

    public function testARepositoryWithoutLocksIsReadPlainly(): void
    {
        $repository = new InMemorySettingValueRepository();
        $repository->apply([(new SettingValue('general', 'site_name', new DateTimeImmutable()))->storeValue('Plain', new DateTimeImmutable())], []);

        SectionLock::lockAll($repository, ['general', 'mailer']);
        $rows = SectionLock::rowsOf($repository, 'general');

        self::assertCount(1, $rows);
        self::assertSame('Plain', $rows[0]->getValue());
    }
}
