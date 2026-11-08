<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\File;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\InstanceSettingsBundle\File\DoctrineSettingFileStore;
use Nowo\InstanceSettingsBundle\File\ImageProcessor;
use Nowo\InstanceSettingsBundle\File\SettingFileRecord;
use Nowo\InstanceSettingsBundle\File\SettingFileStoreInterface;
use Nowo\InstanceSettingsBundle\FileEntity\SettingFile;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;

use function substr;

/**
 * The database store: content-addressed per field, metadata without the blob, orphan marking and purge, table prefix.
 */
final class DoctrineSettingFileStoreTest extends KernelTestCase
{
    public function testStoreLifecycle(): void
    {
        $kernel = $this->bootKernel(['doctrine' => ['table_prefix' => 'isf_'], 'files' => ['enabled' => true]]);
        $store  = $this->container($kernel)->get(SettingFileStoreInterface::class);
        self::assertInstanceOf(DoctrineSettingFileStore::class, $store);
        $image = (new ImageProcessor())->process(new ImageSpec(variants: [16]), ImageFactory::png(64, 32));
        $sha   = $image->sha256();
        $at    = new DateTimeImmutable('2026-11-01 10:00:00');

        $store->save('brand', 'logo', $image, $at);
        $store->save('brand', 'logo', $image, $at);
        $store->save('brand', 'mark', $image, $at);
        self::assertSame(4, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM isf_instance_setting_file'), 'one image + one variant per field');

        $doctrine = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertSame([], $entityManager->getUnitOfWork()->getIdentityMap()[SettingFile::class] ?? [], 'no blob left in the identity map');

        $records = $store->findImage('brand', 'logo', $sha);
        self::assertSame([0, 16], array_keys($records));
        self::assertSame(['image/png', 64, 32, $image->original->byteSize(), null], [$records[0]->mimeType, $records[0]->width, $records[0]->height, $records[0]->byteSize, $records[0]->orphanedAt]);
        self::assertSame([16, 8], [$records[16]->width, $records[16]->height]);
        self::assertSame($image->original->bytes, $store->read($records[0]));
        self::assertSame($image->variants[16]->bytes, $store->read($records[16]));

        $found = $store->find('brand', 'logo', substr($sha, 0, 12), 0);
        self::assertSame($records[0]->id, $found?->id);
        self::assertNull($store->find('brand', 'logo', substr($sha, 0, 12), 32));
        self::assertNull($store->find('brand', 'icon', substr($sha, 0, 12), 0), 'bound to its field');
        $other = (new ImageProcessor())->process(new ImageSpec(), ImageFactory::png(10, 10));
        $store->save('brand', 'logo', $other, $at);
        self::assertNull($store->find('brand', 'logo', '', 0), 'a prefix matching two rows is no match');
        $live = ['brand.logo|' . $sha, 'brand.logo|' . $other->sha256()];

        $store->orphan('brand', 'logo', $sha, $at);
        self::assertEquals($at, $store->findImage('brand', 'logo', $sha)[16]->orphanedAt);
        $store->save('brand', 'logo', $image, $at);
        self::assertNull($store->findImage('brand', 'logo', $sha)[0]->orphanedAt, 'uploading it again references it again');

        self::assertSame(1, $store->markUnreferenced($live, $at, true));
        self::assertNull($store->findImage('brand', 'mark', $sha)[0]->orphanedAt, 'dry run');
        self::assertSame(1, $store->markUnreferenced($live, $at));
        self::assertSame(0, $store->markUnreferenced($live, $at), 'already marked');

        $none = static fn (): array => [];
        self::assertSame(0, $store->purge($at->modify('-1 second'), $none));
        self::assertSame(1, $store->purge($at, $none, true));
        self::assertSame(1, $store->purge($at, $none));
        self::assertSame([], $store->findImage('brand', 'mark', $sha));
        self::assertCount(2, $store->findImage('brand', 'logo', $sha));

        // IO-L1: a wrong mark on a live image (a stale run) is cleared by the next run, and the purge re-checks the
        // references it is handed right before deleting.
        self::assertSame(1, $store->markUnreferenced(['brand.logo|' . $other->sha256()], $at), 'stale run marks the live logo');
        self::assertEquals($at, $store->findImage('brand', 'logo', $sha)[0]->orphanedAt);
        self::assertSame(0, $store->markUnreferenced($live, $at, true));
        self::assertEquals($at, $store->findImage('brand', 'logo', $sha)[0]->orphanedAt, 'dry run keeps the mark');
        self::assertSame(0, $store->markUnreferenced($live, $at), 'a correct run marks nothing');
        self::assertNull($store->findImage('brand', 'logo', $sha)[16]->orphanedAt, 'and clears the stale mark on every row');
        $store->orphan('brand', 'logo', $sha, $at);
        self::assertSame(0, $store->purge($at, static fn (): array => $live), 'referenced at delete time: kept');
        self::assertCount(2, $store->findImage('brand', 'logo', $sha));
        self::assertSame(1, $store->purge($at, static fn (): array => ['brand.logo|' . $other->sha256()]));
        self::assertSame([], $store->findImage('brand', 'logo', $sha));

        $records[0] = new SettingFileRecord(999_999, 'brand', 'logo', $sha, 0, 'image/png', 1, 1, 1);
        self::assertSame('', $store->read($records[0]), 'a row that disappeared reads as empty');
    }

    public function testAClosedEntityManagerIsResetAndANonOrmManagerRefused(): void
    {
        // A configuration of its own: kernels with the same container share its compiled class in one process.
        $kernel   = $this->bootKernel(['files' => ['enabled' => true, 'orphan_retention' => 5]]);
        $doctrine = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->close();

        $store = new DoctrineSettingFileStore($doctrine);
        self::assertSame([], $store->findImage('brand', 'logo', 'x'));

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        $this->expectException(LogicException::class);
        (new DoctrineSettingFileStore($registry, 'other'))->purge(new DateTimeImmutable(), static fn (): array => []);
    }
}
