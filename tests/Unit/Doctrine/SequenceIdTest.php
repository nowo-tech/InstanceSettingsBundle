<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\InstanceSettingsBundle\Doctrine\SequenceId;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use PHPUnit\Framework\TestCase;

/**
 * 1.19: raw inserts take their id from the entity's sequence when the database does not generate it (PostgreSQL with
 * DBAL 3, audit QM-M3).
 */
final class SequenceIdTest extends TestCase
{
    public function testAnIdentityColumnGeneratesItsOwnId(): void
    {
        $metadata = new ClassMetadata(SettingValue::class);
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');

        self::assertNull(SequenceId::next($connection, $metadata));
    }

    public function testASequenceGivesTheNextValue(): void
    {
        $metadata = new ClassMetadata(SettingValue::class);
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_SEQUENCE);
        $metadata->setSequenceGeneratorDefinition(['sequenceName' => 'instance_setting_value_id_seq', 'allocationSize' => '1', 'initialValue' => '1']);
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::once())->method('fetchOne')->with("SELECT NEXTVAL('instance_setting_value_id_seq')")->willReturn('42');

        self::assertSame(42, SequenceId::next($connection, $metadata));
    }

    public function testTheIdColumnIsAddedToARowOnlyForASequence(): void
    {
        $metadata = new ClassMetadata(SettingValue::class);
        $metadata->mapField(['fieldName' => 'id', 'columnName' => 'uid', 'type' => 'integer', 'id' => true]);
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_SEQUENCE);
        $metadata->setSequenceGeneratorDefinition(['sequenceName' => 'seq', 'allocationSize' => '1', 'initialValue' => '1']);
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('fetchOne')->willReturn(7);

        self::assertSame(['section' => 'general', 'uid' => 7], SequenceId::into(['section' => 'general'], $connection, $metadata));

        $identity = new ClassMetadata(SettingValue::class);
        $identity->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_IDENTITY);
        self::assertSame(['section' => 'general'], SequenceId::into(['section' => 'general'], $connection, $identity));
    }

    public function testAnUnreadableValueIsNoId(): void
    {
        $metadata = new ClassMetadata(SettingValue::class);
        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_SEQUENCE);
        $metadata->setSequenceGeneratorDefinition(['sequenceName' => 'seq', 'allocationSize' => '1', 'initialValue' => '1']);
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('fetchOne')->willReturn(false);

        self::assertNull(SequenceId::next($connection, $metadata));
    }
}
