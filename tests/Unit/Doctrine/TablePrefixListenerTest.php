<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\InstanceSettingsBundle\Doctrine\TablePrefixListener;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TablePrefixListenerTest extends TestCase
{
    public function testPrefixesTableAndIndexNames(): void
    {
        $metadata = $this->metadata(SettingValue::class);
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class)));

        self::assertSame('app_instance_setting_value', $metadata->getTableName());
        self::assertArrayHasKey('app_' . SettingValue::UNIQUE_INDEX, $metadata->table['uniqueConstraints'] ?? []);
        self::assertArrayHasKey('app_idx_x', $metadata->table['indexes'] ?? []);
        self::assertArrayHasKey(0, $metadata->table['indexes'] ?? []);
        self::assertSame('app_instance_setting_value', (new TablePrefixListener('app_'))->settingsTableName());
    }

    public function testEmptyPrefixAndForeignEntitiesAreUntouched(): void
    {
        $metadata = $this->metadata(SettingValue::class);
        (new TablePrefixListener())->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class)));
        self::assertSame(SettingValue::TABLE, $metadata->getTableName());

        $foreign = $this->metadata(stdClass::class);
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($foreign, $this->createStub(EntityManagerInterface::class)));
        self::assertSame(SettingValue::TABLE, $foreign->getTableName());
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable([
            'name'              => SettingValue::TABLE,
            'uniqueConstraints' => [SettingValue::UNIQUE_INDEX => ['columns' => ['section', 'name']]],
            'indexes'           => ['idx_x' => ['columns' => ['name']], 0 => ['columns' => ['section']]],
        ]);

        return $metadata;
    }
}
