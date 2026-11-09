<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\DriverManager;
use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionConnection;
use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionMiddleware;
use Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore;
use Nowo\InstanceSettingsBundle\File\ImageProcessor;
use Nowo\InstanceSettingsBundle\File\ProcessedImage;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function bin2hex;
use function file_exists;
use function file_get_contents;
use function random_bytes;
use function sys_get_temp_dir;

final class FileStoreTransactionMiddlewareTest extends TestCase
{
    private string $dir;

    private FilesystemSettingFileStore $store;

    private ProcessedImage $old;

    private ProcessedImage $new;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/nowo-is-mw-' . bin2hex(random_bytes(6));
        $this->store = new FilesystemSettingFileStore($this->dir);
        $this->old   = (new ImageProcessor())->process(new ImageSpec(), ImageFactory::png(16, 16));
        $this->new   = (new ImageProcessor())->process(new ImageSpec(), ImageFactory::png(16, 16, 10));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testFileChangesFollowTheRealTransaction(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $this->save($this->new);
        $connection->rollBack();
        self::assertFalse(file_exists($this->dir . '/brand/logo.png'), 'rolled back: discarded');

        $connection->beginTransaction();
        $this->save($this->new);
        $connection->commit();
        self::assertSame($this->new->original->bytes, file_get_contents($this->dir . '/brand/logo.png'));
    }

    public function testSavepointsAreFollowed(): void
    {
        $connection = $this->connection();
        $this->save($this->old);

        // Savepoint rolled back around a clear, outer commit: the image is intact (JG-M3).
        $connection->beginTransaction();
        $connection->beginTransaction();
        $this->store->orphan('brand', 'logo', $this->old->sha256(), new DateTimeImmutable());
        $connection->rollBack();
        $connection->commit();
        self::assertSame($this->old->original->bytes, file_get_contents($this->dir . '/brand/logo.png'));

        // Around a replace.
        $connection->beginTransaction();
        $connection->beginTransaction();
        $this->save($this->new);
        $connection->rollBack();
        $connection->commit();
        self::assertSame($this->old->original->bytes, file_get_contents($this->dir . '/brand/logo.png'));

        // Savepoint committed, outer rolled back: dropped.
        $connection->beginTransaction();
        $connection->beginTransaction();
        $this->save($this->new);
        $connection->commit();
        $connection->rollBack();
        self::assertSame($this->old->original->bytes, file_get_contents($this->dir . '/brand/logo.png'));

        // Savepoint committed, outer committed: applied.
        $connection->beginTransaction();
        $connection->beginTransaction();
        $this->save($this->new);
        $connection->commit();
        self::assertSame($this->old->original->bytes, file_get_contents($this->dir . '/brand/logo.png'), 'a savepoint is not the real commit');
        $connection->commit();
        self::assertSame($this->new->original->bytes, file_get_contents($this->dir . '/brand/logo.png'));
    }

    public function testAnotherConnectionNeitherDelaysNorDiscards(): void
    {
        $bundle = $this->connection();
        $other  = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $other->beginTransaction();
        $this->save($this->old);
        self::assertFileExists($this->dir . '/brand/logo.png', 'not delayed by the other connection');
        $other->rollBack();
        self::assertFileExists($this->dir . '/brand/logo.png');

        $bundle->beginTransaction();
        $other->beginTransaction();
        $this->save($this->new);
        $other->rollBack();
        $bundle->commit();
        self::assertSame($this->new->original->bytes, file_get_contents($this->dir . '/brand/logo.png'), 'not discarded by the other connection');
    }

    public function testSqlServerSavepointStatementsAndAFailedCommit(): void
    {
        $driver = $this->createMock(Connection::class);
        $driver->method('exec')->willReturn(0);
        $driver->method('commit')->willThrowException(new RuntimeException('deadlock'));
        $connection = new FileStoreTransactionConnection($driver, $this->store);

        $connection->beginTransaction();
        self::assertSame(0, $connection->exec('SAVE TRANSACTION DOCTRINE_2'));
        $this->save($this->new);
        $connection->exec('ROLLBACK TRANSACTION DOCTRINE_2');
        $connection->exec('SELECT 1');
        $this->store->transactionEnded($connection, true);
        self::assertFalse(file_exists($this->dir . '/brand/logo.png'), 'rolled back to the savepoint');

        $connection->beginTransaction();
        $this->save($this->new);
        try {
            $connection->commit();
            self::fail('Expected the commit failure.');
        } catch (RuntimeException $e) {
            self::assertSame('deadlock', $e->getMessage());
        }
        self::assertFalse(file_exists($this->dir . '/brand/logo.png'));

        $this->save($this->new);
        self::assertFileExists($this->dir . '/brand/logo.png', 'no transaction left open');
    }

    private function connection(): DbalConnection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new FileStoreTransactionMiddleware($this->store)]));
    }

    private function save(ProcessedImage $image): void
    {
        $this->store->save('brand', 'logo', $image, new DateTimeImmutable());
    }
}
