<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\SectionTest;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\SectionTest\DoctrineSectionTestStateStorage;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestStateRow;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestStore;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;

/**
 * 1.19: the insert of a section test row runs in a unit of the bundle's runner; only a lost unique key is a "taken" row.
 */
final class DoctrineSectionTestStateStorageTest extends KernelTestCase
{
    public function testALostInsertIsNullAndAnyOtherFailureReachesTheCaller(): void
    {
        $kernel  = $this->bootKernel();
        $manager = $this->container($kernel)->get(SettingsEntityManager::class);
        self::assertInstanceOf(SettingsEntityManager::class, $manager);
        $storage = new DoctrineSectionTestStateStorage($manager);
        $at      = new DateTimeImmutable('2026-11-15 10:00:00');

        self::assertInstanceOf(SectionTestStateRow::class, $storage->insert(SectionTestStore::LOCKS, 'marker', 'token-1', $at));
        self::assertNull($storage->insert(SectionTestStore::LOCKS, 'marker', 'token-2', $at), 'the row is taken');
        self::assertSame('token-1', $storage->rows(SectionTestStore::LOCKS)[0]->value);

        $this->connection($kernel)->executeStatement('DROP TABLE instance_setting_value');
        $this->expectException(TableNotFoundException::class);
        $storage->insert(SectionTestStore::LOCKS, 'other', 'token-3', $at);
    }
}
