<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\DriverManager;
use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionConnection;
use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionMiddleware;
use Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore;
use Nowo\InstanceSettingsBundle\File\ImageProcessor;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function bin2hex;
use function file_exists;
use function random_bytes;
use function sys_get_temp_dir;

final class FileStoreTransactionMiddlewareTest extends TestCase
{
    private string $dir;

    private FilesystemSettingFileStore $store;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/nowo-is-mw-' . bin2hex(random_bytes(6));
        $this->store = new FilesystemSettingFileStore($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testFileChangesFollowTheRealTransaction(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new FileStoreTransactionMiddleware($this->store)]));
        $image      = (new ImageProcessor())->process(new ImageSpec(), ImageFactory::png(16, 16));

        $connection->beginTransaction();
        $connection->beginTransaction();
        $this->store->save('brand', 'logo', $image, new DateTimeImmutable());
        $connection->commit();
        self::assertFalse(file_exists($this->dir . '/brand/logo.png'), 'a savepoint is not the real commit');
        $connection->rollBack();
        self::assertFalse(file_exists($this->dir . '/brand/logo.png'), 'rolled back: discarded');

        $connection->beginTransaction();
        $this->store->save('brand', 'logo', $image, new DateTimeImmutable());
        $connection->commit();
        self::assertFileExists($this->dir . '/brand/logo.png');
    }

    public function testAFailedCommitCountsAsARollback(): void
    {
        $driver = $this->createMock(Connection::class);
        $driver->method('commit')->willThrowException(new RuntimeException('deadlock'));
        $connection = new FileStoreTransactionConnection($driver, $this->store);
        $image      = (new ImageProcessor())->process(new ImageSpec(), ImageFactory::png(16, 16));

        $connection->beginTransaction();
        $this->store->save('brand', 'logo', $image, new DateTimeImmutable());
        try {
            $connection->commit();
            self::fail('Expected the commit failure.');
        } catch (RuntimeException $e) {
            self::assertSame('deadlock', $e->getMessage());
        }
        self::assertFalse(file_exists($this->dir . '/brand/logo.png'));

        $this->store->save('brand', 'logo', $image, new DateTimeImmutable());
        self::assertFileExists($this->dir . '/brand/logo.png', 'no transaction left open');
    }
}
