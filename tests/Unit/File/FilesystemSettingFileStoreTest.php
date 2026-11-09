<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\File;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore;
use Nowo\InstanceSettingsBundle\File\ImageProcessor;
use Nowo\InstanceSettingsBundle\File\ProcessedImage;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function bin2hex;
use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function fileinode;
use function fileperms;
use function glob;
use function hash;
use function is_file;
use function mkdir;
use function random_bytes;
use function str_repeat;
use function substr;
use function symlink;
use function sys_get_temp_dir;
use function umask;

final class FilesystemSettingFileStoreTest extends TestCase
{
    private string $dir;

    private FilesystemSettingFileStore $store;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/nowo-is-fs-' . bin2hex(random_bytes(6));
        $this->store = new FilesystemSettingFileStore($this->dir . '/files');
        $this->now   = new DateTimeImmutable('2026-11-09 10:00:00');

    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testSaveWritesAStableFileAndVariantsWithSanePermissions(): void
    {
        $image = $this->image(ImageFactory::png(64, 64), [32, 16]);
        $this->store->save('brand', 'logo', $image, $this->now);

        $root = (string) realpath($this->dir . '/files');
        self::assertSame($image->original->bytes, file_get_contents($root . '/brand/logo.png'));
        self::assertSame(0o644 & ~umask(), fileperms($root . '/brand/logo.png') & 0o777);
        self::assertSame(0o755 & ~umask(), fileperms($root . '/brand') & 0o777);
        $sha12 = substr($image->sha256(), 0, 12);
        self::assertFileExists($root . '/brand/.variants/logo-' . $sha12 . '-32.png');
        self::assertFileExists($root . '/brand/.variants/logo-' . $sha12 . '-16.png');
        self::assertSame([], glob($root . '/brand/.tmp-*'), 'no temporary file left');
        self::assertSame($root . '/brand/logo.png', $this->store->originalPath('brand', 'logo', 'image/png'));

        $record = $this->store->find('brand', 'logo', $sha12, 0);
        self::assertNotNull($record);
        self::assertSame([$image->sha256(), 'image/png', 64, 64, $image->original->byteSize()], [$record->sha256, $record->mimeType, $record->width, $record->height, $record->byteSize]);
        self::assertSame($image->original->bytes, $this->store->read($record));
        $variant = $this->store->find('brand', 'logo', $image->sha256(), 32);
        self::assertNotNull($variant);
        self::assertSame([32, 'image/png', 32], [$variant->variant, $variant->mimeType, $variant->width]);
        self::assertSame($image->variants[32]->bytes, $this->store->read($variant));
        self::assertSame([0, 32, 16], array_keys($this->store->findImage('brand', 'logo', str_repeat('0', 64))), 'the file is authoritative, whatever hash the row holds');

        self::assertNull($this->store->find('brand', 'logo', substr($sha12, 0, 11), 0), 'prefix too short');
        self::assertNull($this->store->find('brand', 'logo', 'ffffffffffff', 0));
        self::assertNull($this->store->find('brand', 'logo', $sha12, 64), 'undeclared variant');
        self::assertNull($this->store->find('brand', 'other', $sha12, 0));
        self::assertNull($this->store->find('Brand', 'logo', $sha12, 0), 'names outside the allowlist');
        self::assertSame([], $this->store->findImage('brand', 'other', $image->sha256()));
        self::assertSame(0, $this->store->markUnreferenced([], $this->now));
    }

    public function testSavingTheSameImageAgainDoesNotRewriteIt(): void
    {
        $image = $this->image(ImageFactory::png(40, 40));
        $this->store->save('brand', 'mark', $image, $this->now);
        $path  = (string) $this->store->originalPath('brand', 'mark', 'image/png');
        $inode = fileinode($path);

        $this->store->save('brand', 'mark', $image, $this->now);
        clearstatcache();
        self::assertSame($inode, fileinode($path));
    }

    public function testReplacingWithAnotherTypeRemovesTheOldFileAndVariants(): void
    {
        $png = $this->image(ImageFactory::png(64, 64), [32]);
        $this->store->save('brand', 'logo', $png, $this->now);
        $webp = $this->image(ImageFactory::webp(64, 64, 10), [32]);
        $this->store->save('brand', 'logo', $webp, $this->now);
        $this->store->orphan('brand', 'logo', $png->sha256(), $this->now);

        $root = (string) realpath($this->dir . '/files');
        self::assertFileDoesNotExist($root . '/brand/logo.png');
        self::assertFileExists($root . '/brand/logo.webp');
        self::assertSame([$root . '/brand/.variants/logo-' . substr($webp->sha256(), 0, 12) . '-32.png'], glob($root . '/brand/.variants/*'));
        self::assertNull($this->store->find('brand', 'logo', $png->sha256(), 0), 'the old URL is gone');
        self::assertSame('image/webp', $this->store->find('brand', 'logo', $webp->sha256(), 0)?->mimeType);

        // Clearing the field deletes its file at once.
        $this->store->orphan('brand', 'logo', $webp->sha256(), $this->now);
        self::assertFileDoesNotExist($root . '/brand/logo.webp');
        self::assertSame([], glob($root . '/brand/.variants/*'));
        $this->store->orphan('brand', 'logo', $webp->sha256(), $this->now);
    }

    public function testReadReturnsNothingWhenTheFileChangedOrIsGone(): void
    {
        $image = $this->image(ImageFactory::png(64, 64), [32]);
        $this->store->save('brand', 'logo', $image, $this->now);
        $record  = $this->store->find('brand', 'logo', $image->sha256(), 0);
        $variant = $this->store->find('brand', 'logo', $image->sha256(), 32);
        self::assertNotNull($record);
        self::assertNotNull($variant);

        file_put_contents((string) $this->store->originalPath('brand', 'logo', 'image/png'), ImageFactory::png(64, 64, 10));
        self::assertSame('', $this->store->read($record), 'changed meanwhile');
        $this->store->save('brand', 'logo', $this->image(ImageFactory::png(64, 64, 30), [32]), $this->now);
        self::assertSame('', $this->store->read($variant), 'variant of the old hash removed');
    }

    public function testAdoptKeepsTheCommittedBytesAndWritesTheVariants(): void
    {
        $root = $this->dir . '/files';
        mkdir($root . '/brand', 0o755, true);
        $bytes = ImageFactory::png(64, 64, 77);
        file_put_contents($root . '/brand/app_icon.png', $bytes);
        $inode = fileinode($root . '/brand/app_icon.png');

        $sha = $this->store->adopt('brand', 'app_icon', $bytes, $this->image($bytes, [32]));
        clearstatcache();
        self::assertSame(hash('sha256', $bytes), $sha);
        self::assertSame($inode, fileinode($root . '/brand/app_icon.png'), 'not rewritten');
        self::assertSame($bytes, file_get_contents($root . '/brand/app_icon.png'));
        self::assertNotNull($this->store->find('brand', 'app_icon', $sha, 32));
    }

    public function testInvalidFilesOnDiskAreNeverServed(): void
    {
        $root = $this->dir . '/files';
        mkdir($root . '/brand', 0o755, true);
        file_put_contents($root . '/brand/a.webp', ImageFactory::png(8, 8));
        file_put_contents($root . '/brand/b.png', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        file_put_contents($root . '/brand/c.png', '');
        file_put_contents($root . '/brand/d.png', "\x89PNG\r\n\x1a\n" . str_repeat('x', ImageSpec::MAX_BYTES_CEILING));
        file_put_contents($root . '/brand/e.png', "\x89PNG\r\n\x1a\nnot really");
        file_put_contents($this->dir . '/outside.png', ImageFactory::png(8, 8));
        symlink($this->dir . '/outside.png', $root . '/brand/f.png');

        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $field) {
            self::assertSame([], $this->store->findImage('brand', $field, ''), $field);
        }
        $record = $this->store->find('brand', 'g', '000000000000', 0);
        self::assertNull($record);
    }

    public function testWritesAreConfinedAndRefuseSymlinks(): void
    {
        $image = $this->image(ImageFactory::png(64, 64), [32]);
        $root  = $this->dir . '/files';
        mkdir($root, 0o755, true);
        mkdir($this->dir . '/elsewhere', 0o755, true);

        symlink($this->dir . '/elsewhere', $root . '/brand');
        $this->assertRefused(fn () => $this->store->save('brand', 'logo', $image, $this->now), 'symlink');
        self::assertSame([], glob($this->dir . '/elsewhere/*'));

        mkdir($root . '/site', 0o755);
        symlink($this->dir . '/elsewhere/logo.png', $root . '/site/logo.png');
        $this->assertRefused(fn () => $this->store->save('site', 'logo', $image, $this->now), 'symlink');
        self::assertFileDoesNotExist($this->dir . '/elsewhere/logo.png');

        mkdir($root . '/shop', 0o755);
        symlink($this->dir . '/elsewhere', $root . '/shop/.variants');
        $this->assertRefused(fn () => $this->store->save('shop', 'logo', $image, $this->now), 'symlink');

        mkdir($root . '/blog/logo.png', 0o755, true);
        $this->assertRefused(fn () => $this->store->save('blog', 'logo', $image, $this->now), 'could not write');

        $this->assertRefused(fn () => $this->store->save('brand', '../logo', $image, $this->now), 'invalid section / field');
        self::assertNull($this->store->originalPath('brand', 'logo', 'image/gif'));
    }

    public function testAnUnusableDirectoryIsAClearError(): void
    {
        mkdir($this->dir, 0o755, true);
        file_put_contents($this->dir . '/files', 'not a directory');
        $this->assertRefused(fn () => $this->store->save('brand', 'logo', $this->image(ImageFactory::png(8, 8)), $this->now), 'cannot create the directory');

        $store = new FilesystemSettingFileStore($this->dir . '/missing');
        self::assertSame($this->dir . '/missing/brand/logo.png', $store->originalPath('brand', 'logo', 'image/png'));
        self::assertSame([], $store->findImage('brand', 'logo', ''));
        self::assertSame(0, $store->purge($this->now, static fn (): array => []));
    }

    public function testPurgeRemovesStaleVariantsAndTemporaryFilesButNeverOriginals(): void
    {
        $image = $this->image(ImageFactory::png(64, 64), [32]);
        $this->store->save('brand', 'logo', $image, $this->now);
        $root     = (string) realpath($this->dir . '/files');
        $variants = $root . '/brand/.variants';
        file_put_contents($variants . '/logo-ffffffffffff-32.png', 'stale');
        file_put_contents($variants . '/gone-ffffffffffff-32.png', 'no original');
        file_put_contents($variants . '/README', 'left alone');
        file_put_contents($variants . '/.tmp-abc', 'crashed write');
        file_put_contents($root . '/brand/.tmp-def', 'crashed write');
        file_put_contents($root . '/brand/unseeded.png', ImageFactory::png(8, 8));
        mkdir($root . '/Not-A-Section', 0o755);
        mkdir($root . '/empty', 0o755);
        mkdir($this->dir . '/elsewhere', 0o755);
        file_put_contents($this->dir . '/elsewhere/.tmp-x', 'outside');
        symlink($this->dir . '/elsewhere', $root . '/linked');

        self::assertSame(4, $this->store->purge($this->now, static fn (): array => [], true));
        self::assertFileExists($variants . '/logo-ffffffffffff-32.png', 'dry run');
        self::assertSame(4, $this->store->purge($this->now, static fn (): array => []));
        self::assertFileDoesNotExist($variants . '/logo-ffffffffffff-32.png');
        self::assertFileDoesNotExist($variants . '/gone-ffffffffffff-32.png');
        self::assertFileDoesNotExist($root . '/brand/.tmp-def');
        self::assertFileExists($variants . '/README');
        self::assertFileExists($root . '/brand/unseeded.png', 'an original is never purged');
        self::assertFileExists($root . '/brand/logo.png');
        self::assertFileExists($variants . '/logo-' . substr($image->sha256(), 0, 12) . '-32.png');
        self::assertFileExists($this->dir . '/elsewhere/.tmp-x');
        self::assertTrue(is_file($root . '/brand/logo.png'));
    }

    /**
     * @param list<int> $variants
     */
    private function image(string $bytes, array $variants = []): ProcessedImage
    {
        return (new ImageProcessor())->process(new ImageSpec(variants: $variants), $bytes);
    }

    private function assertRefused(callable $callback, string $message): void
    {
        try {
            $callback();
            self::fail('Expected a refusal.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
