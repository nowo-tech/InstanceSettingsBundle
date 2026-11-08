<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\File;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\InstanceSettingsBundle\Command\PurgeFilesCommand;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\File\SettingFileController;
use Nowo\InstanceSettingsBundle\File\SettingFileStoreInterface;
use Nowo\InstanceSettingsBundle\FileEntity\SettingFile;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

use function array_map;
use function sort;

/**
 * `files.enabled` (1.4, coordinator decision Q1): off by default, the file entity is not mapped and the schema is the
 * 1.3 one (settings table only); on, the file table exists with and without `doctrine.table_prefix`. An image field
 * without the flag fails closed when the section definitions load.
 */
final class FileTableOptInTest extends KernelTestCase
{
    public function testByDefaultTheSchemaIsTheOneOf13(): void
    {
        $kernel        = $this->bootKernel(['files' => ['orphan_retention' => 11]]);
        $entityManager = $this->entityManager($kernel);
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();

        self::assertSame([SettingValue::class], array_map(static fn (ClassMetadata $m): string => $m->getName(), $metadata));
        $tool = new SchemaTool($entityManager);
        self::assertSame($tool->getCreateSchemaSql([$entityManager->getClassMetadata(SettingValue::class)]), $tool->getCreateSchemaSql($metadata), 'identical to the 1.3 schema');
        self::assertSame(['instance_setting_value'], $this->tables($kernel));

        $container = $this->container($kernel);
        self::assertFalse($container->has(SettingFileStoreInterface::class));
        self::assertFalse($container->has(SettingFileController::class));
        self::assertFalse($container->has(PurgeFilesCommand::class));
    }

    public function testWithTheFlagTheTableExistsWithAndWithoutPrefix(): void
    {
        $plain = $this->bootKernel(['files' => ['enabled' => true, 'orphan_retention' => 12]]);
        self::assertSame(['instance_setting_file', 'instance_setting_value'], $this->tables($plain));
        self::assertSame(SettingFile::TABLE, $this->entityManager($plain)->getClassMetadata(SettingFile::class)->getTableName());

        $prefixed = $this->bootKernel(['doctrine' => ['table_prefix' => 'acme_'], 'files' => ['enabled' => true, 'orphan_retention' => 13]]);
        self::assertSame(['acme_instance_setting_file', 'acme_instance_setting_value'], $this->tables($prefixed));
        self::assertSame('acme_' . SettingFile::TABLE, $this->entityManager($prefixed)->getClassMetadata(SettingFile::class)->getTableName());
    }

    public function testAnImageFieldWithoutTheFlagFailsClosed(): void
    {
        $kernel = $this->bootKernel(['files' => ['orphan_retention' => 14]], services: [ImageFixtureSection::class => ImageFixtureSection::class]);
        $router = $this->container($kernel)->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        try {
            $router->getRouteCollection();
            self::fail('An image field without files.enabled must fail closed.');
        } catch (Throwable $e) {
            $previous = $e->getPrevious() ?? $e;
            self::assertInstanceOf(LogicException::class, $previous);
            self::assertStringContainsString('declares the image field "logo": set nowo_instance_settings.files.enabled: true', $previous->getMessage());
        }
    }

    private function entityManager(InstanceSettingsTestKernel $kernel): EntityManagerInterface
    {
        $doctrine = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * @return list<string>
     */
    private function tables(InstanceSettingsTestKernel $kernel): array
    {
        $tables = $this->connection($kernel)->createSchemaManager()->listTableNames();
        sort($tables);

        return $tables;
    }
}
