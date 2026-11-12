<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\MappingException;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\EntityManagerMappingPass;
use Nowo\InstanceSettingsBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\FileEntity\SettingFile;
use PHPUnit\Framework\TestCase;

final class ExcludedEntityNamespaceDriverTest extends TestCase
{
    public function testMapsNothing(): void
    {
        $driver = new ExcludedEntityNamespaceDriver();

        self::assertTrue($driver->isTransient(SettingValue::class));
        self::assertSame([], $driver->getAllClassNames());

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(SettingValue::class);
        $driver->loadMetadataForClass(SettingValue::class, new ClassMetadata(SettingValue::class));
    }

    /**
     * A chain asks its namespace drivers before its default driver: a default driver that claims every entity class
     * no longer claims the bundle entities.
     */
    public function testWinsOverTheDefaultDriverOfAChain(): void
    {
        $catchAll = $this->createStub(MappingDriver::class);
        $catchAll->method('isTransient')->willReturn(false);
        $chain = new MappingDriverChain();
        $chain->setDefaultDriver($catchAll);
        self::assertFalse($chain->isTransient(SettingValue::class));
        self::assertFalse($chain->isTransient(SettingFile::class));

        $chain->addDriver(new ExcludedEntityNamespaceDriver(), EntityManagerMappingPass::ENTITY_NAMESPACE);
        $chain->addDriver(new ExcludedEntityNamespaceDriver(), EntityManagerMappingPass::FILE_ENTITY_NAMESPACE);

        self::assertTrue($chain->isTransient(SettingValue::class));
        self::assertTrue($chain->isTransient(SettingFile::class));
        self::assertFalse($chain->isTransient(self::class), 'other classes still go to the default driver');
    }
}
