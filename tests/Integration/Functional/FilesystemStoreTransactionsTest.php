<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use LogicException;
use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\FailingImportProvider;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash;
use function mkdir;
use function substr;
use function symlink;
use function unlink;

/**
 * 1.7 audit JG: the filesystem store's file changes follow the database transaction (JG-M1), a store switch re-writes
 * an image of the same hash (JG-M2), a file without a setting row is not served (JG-L3), the public web root is refused
 * (JG-L4).
 */
final class FilesystemStoreTransactionsTest extends KernelTestCase
{
    private string $files;

    public function testARolledBackClearKeepsTheImage(): void
    {
        $kernel   = $this->kernel();
        $settings = $this->settings($kernel);
        $settings->save('brand', ['logo_light' => ImageUpload::fromBytes(ImageFactory::png(240, 80))]);
        $bytes = (string) file_get_contents($this->files . '/brand/logo_light.png');

        $failed = null;
        try {
            $this->repository($kernel)->transactional(static function () use ($settings): never {
                $settings->save('brand', ['logo_light' => null]);

                throw new RuntimeException('a later step of the host transaction failed');
            });
        } catch (RuntimeException $e) {
            $failed = $e->getMessage();
        }
        self::assertSame('a later step of the host transaction failed', $failed);

        self::assertSame($bytes, (string) file_get_contents($this->files . '/brand/logo_light.png'));
        $settings->reset();
        self::assertSame(hash('sha256', $bytes), $settings->getFile('brand.logo_light')?->sha256);
        self::assertSame([], $this->temps($this->files . '/brand'));

        // Committed, the clear deletes the file.
        $this->repository($kernel)->transactional(static fn (): array => $settings->save('brand', ['logo_light' => null]));
        self::assertSame([], glob($this->files . '/brand/logo_light.*'));
    }

    public function testAHostSavepointRollbackKeepsTheImage(): void
    {
        $kernel     = $this->kernel();
        $settings   = $this->settings($kernel);
        $connection = $this->connection($kernel);
        $settings->save('brand', ['logo_light' => ImageUpload::fromBytes(ImageFactory::png(240, 80))]);
        $bytes = (string) file_get_contents($this->files . '/brand/logo_light.png');

        // Clear inside a savepoint that is rolled back, outer transaction committed (JG-M3).
        $connection->beginTransaction();
        $connection->beginTransaction();
        $settings->save('brand', ['logo_light' => null]);
        $connection->rollBack();
        $connection->commit();
        $settings->reset();
        self::assertSame($bytes, (string) file_get_contents($this->files . '/brand/logo_light.png'));
        self::assertSame(hash('sha256', $bytes), $settings->getFile('brand.logo_light')?->sha256);

        // Replace inside a savepoint that is rolled back.
        $connection->beginTransaction();
        $connection->beginTransaction();
        $settings->save('brand', ['logo_light' => ImageUpload::fromBytes(ImageFactory::png(240, 80, 10))]);
        $connection->rollBack();
        $connection->commit();
        $settings->reset();
        self::assertSame($bytes, (string) file_get_contents($this->files . '/brand/logo_light.png'));
        self::assertSame(hash('sha256', $bytes), $settings->getFile('brand.logo_light')?->sha256);
        self::assertSame([], self::temps($this->files . '/brand'));

        // Savepoint released, outer committed: applied (row and disk agree).
        $connection->beginTransaction();
        $connection->beginTransaction();
        $settings->save('brand', ['logo_light' => ImageUpload::fromBytes(ImageFactory::png(240, 80, 10))]);
        $connection->commit();
        $connection->commit();
        $settings->reset();
        $now = (string) file_get_contents($this->files . '/brand/logo_light.png');
        self::assertNotSame($bytes, $now);
        self::assertSame(hash('sha256', $now), $settings->getFile('brand.logo_light')?->sha256);
        self::assertSame('"isf:' . hash('sha256', $now) . '"', $this->connection($kernel)->fetchOne("SELECT value FROM instance_setting_value WHERE name = 'logo_light'"));
    }

    public function testASaveWhoseSecondFieldFailsKeepsTheFirstFieldsImage(): void
    {
        $kernel   = $this->kernel();
        $settings = $this->settings($kernel);
        $settings->save('brand', ['logo_light' => ImageUpload::fromBytes(ImageFactory::png(240, 80))]);
        $before = (string) file_get_contents($this->files . '/brand/logo_light.png');
        symlink($this->files . '/../victim.png', $this->files . '/brand/logo_dark.png');

        try {
            $settings->save('brand', [
                'logo_light' => ImageUpload::fromBytes(ImageFactory::png(240, 80, 10)),
                'logo_dark'  => ImageUpload::fromBytes(ImageFactory::png(240, 80, 20)),
            ]);
            self::fail('Expected a refusal.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('symlink', $e->getMessage());
        }
        self::assertSame($before, (string) file_get_contents($this->files . '/brand/logo_light.png'));
        self::assertFileDoesNotExist($this->files . '/../victim.png');
        self::assertSame([], $this->temps($this->files . '/brand'));
    }

    public function testAnImportWhoseTransactionFailsChangesNoFile(): void
    {
        $kernel   = $this->kernel([FailingImportProvider::class => FailingImportProvider::class]);
        $settings = $this->settings($kernel);
        $settings->save('brand', ['logo_light' => ImageUpload::fromBytes(ImageFactory::png(240, 80))]);
        $portability = $this->container($kernel)->get(ConfigPortability::class);
        self::assertInstanceOf(ConfigPortability::class, $portability);
        $envelope = $portability->export(['brand'], includeFiles: true);
        unset($envelope['providers']);
        $envelope['providers'] = ['failing' => ['enabled' => true]];

        $settings->save('brand', ['logo_light' => ImageUpload::fromBytes(ImageFactory::webp(240, 80, 10))]);
        $current = (string) file_get_contents($this->files . '/brand/logo_light.webp');
        try {
            $portability->import($envelope);
            self::fail('Expected the provider failure.');
        } catch (Throwable $e) {
            self::assertStringContainsString('provider import failed', $e->getMessage());
        }

        self::assertSame($current, (string) file_get_contents($this->files . '/brand/logo_light.webp'));
        self::assertFileDoesNotExist($this->files . '/brand/logo_light.png');
        self::assertSame([], $this->temps($this->files . '/brand'));
    }

    public function testAfterAStoreSwitchTheSameImageIsWrittenAgain(): void
    {
        $kernel   = $this->kernel();
        $settings = $this->settings($kernel);
        $png      = ImageFactory::png(240, 80);
        $settings->save('brand', ['logo_light' => ImageUpload::fromBytes($png)]);
        $portability = $this->container($kernel)->get(ConfigPortability::class);
        self::assertInstanceOf(ConfigPortability::class, $portability);
        $envelope = $portability->export(['brand'], includeFiles: true);

        // As after switching from the database store: the row holds isf:<sha>, the directory has no file.
        unlink($this->files . '/brand/logo_light.png');
        $settings->reset();
        self::assertNull($settings->getFile('brand.logo_light'));

        self::assertSame(['logo_light'], $settings->save('brand', ['logo_light' => ImageUpload::fromBytes($png)]), 're-upload of the same image (JG-M2)');
        self::assertFileExists($this->files . '/brand/logo_light.png');

        unlink($this->files . '/brand/logo_light.png');
        $portability->import($envelope);
        self::assertFileExists($this->files . '/brand/logo_light.png', 'import of the same image');
        $settings->reset();
        self::assertNotNull($settings->getFile('brand.logo_light'));
        self::assertSame([], $settings->save('brand', ['logo_light' => ImageUpload::fromBytes($png)]), 'held by the store: unchanged');
    }

    public function testAFileWithoutASettingRowIsNotServed(): void
    {
        $kernel = $this->kernel();
        mkdir($this->files . '/brand', 0o755, true);
        $bytes = ImageFactory::png(240, 80);
        file_put_contents($this->files . '/brand/logo_light.png', $bytes);
        $url = '/_settings-file/brand/logo_light/' . substr(hash('sha256', $bytes), 0, 12) . '.png';

        $client = $this->client($kernel, null);
        $client->request('GET', $url);
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'committed but not seeded');

        file_put_contents($this->files . '/seed.yaml', "schema: nowo-instance-settings-seed\nversion: 1\nfiles:\n  brand:\n    logo_light: brand/logo_light.png\n");
        $tester = new CommandTester((new Application($kernel))->find('nowo:instance-settings:seed'));
        self::assertSame(0, $tester->execute(['file' => $this->files . '/seed.yaml']), $tester->getDisplay());
        $settings = $this->settings($kernel);
        $settings->reset();
        $client->request('GET', (string) $settings->getFile('brand.logo_light')?->url());
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testTheDirectoryMayNotBeUnderThePublicWebRoot(): void
    {
        foreach ([
            ['store' => 'filesystem', 'directory' => '%kernel.project_dir%/public/brand-files'],
            ['store' => 'filesystem', 'directory' => InstanceSettingsTestKernel::baseDir() . '/web/files'],
        ] as $files) {
            try {
                $this->bootKernel(['brand' => ['enabled' => true, 'public_dir' => InstanceSettingsTestKernel::baseDir() . '/web', 'defaults' => ['name' => 'Acme']], 'files' => $files], fixtures: false, extensions: ['nowo_ui_kit' => ['appearance' => ['enabled' => true]]]);
                self::fail('Expected the refusal.');
            } catch (LogicException $e) {
                self::assertStringContainsString('under the public web root', $e->getMessage());
            }
        }
    }

    /**
     * @param array<string, string> $services
     */
    private function kernel(array $services = []): InstanceSettingsTestKernel
    {
        $this->files = InstanceSettingsTestKernel::baseDir() . '/fs-' . $this->name() . '/files';

        return $this->bootKernel(
            [
                'brand' => ['enabled' => true, 'defaults' => ['name' => 'Acme']],
                'files' => ['store' => 'filesystem', 'directory' => $this->files],
                'seed'  => ['base_dir' => InstanceSettingsTestKernel::baseDir()],
            ],
            $services,
            fixtures: false,
            extensions: ['nowo_ui_kit' => ['appearance' => ['enabled' => true]]],
        );
    }

    private function repository(InstanceSettingsTestKernel $kernel): SettingValueRepositoryInterface
    {
        $repository = $this->container($kernel)->get(SettingValueRepositoryInterface::class);
        self::assertInstanceOf(SettingValueRepositoryInterface::class, $repository);

        return $repository;
    }

    /**
     * Temporary files left in a section directory and its variants.
     *
     * @return list<string>
     */
    private function temps(string $directory): array
    {
        return [...(glob($directory . '/.tmp-*') ?: []), ...(glob($directory . '/.variants/.tmp-*') ?: [])];
    }
}
