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
use stdClass;
use Symfony\Component\Filesystem\Filesystem;

use function bin2hex;
use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function fileinode;
use function fileperms;
use function glob;
use function hash;
use function json_decode;
use function json_encode;
use function mkdir;
use function random_bytes;
use function realpath;
use function str_repeat;
use function substr;
use function symlink;
use function sys_get_temp_dir;
use function time;
use function touch;
use function umask;
use function unlink;

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

    public function testSaveWritesAStableFileVariantsAndMetadataWithSanePermissions(): void
    {
        $image = $this->image(ImageFactory::png(64, 64), [32, 16]);
        $this->store->save('brand', 'logo', $image, $this->now);

        $root = $this->root();
        self::assertSame($image->original->bytes, file_get_contents($root . '/brand/logo.png'));
        self::assertSame(0o644 & ~umask(), fileperms($root . '/brand/logo.png') & 0o777);
        self::assertSame(0o755 & ~umask(), fileperms($root . '/brand') & 0o777);
        $sha12 = substr($image->sha256(), 0, 12);
        self::assertFileExists($root . '/brand/.variants/logo-' . $sha12 . '-32.png');
        self::assertFileExists($root . '/brand/.variants/logo-' . $sha12 . '-16.png');
        self::assertFileExists($root . '/brand/.variants/logo.meta');
        self::assertSame([], $this->temps($root . '/brand'), 'no temporary file left');
        self::assertSame($root . '/brand/logo.png', $this->store->storedPath('brand', 'logo'));

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
        self::assertNull($this->store->find("brand\n", 'logo', $sha12, 0), 'no trailing newline (JG-I5)');
        self::assertNull($this->store->storedPath('brand', 'other'));
        self::assertSame([], $this->store->findImage('brand', 'other', $image->sha256()));
        self::assertSame(0, $this->store->markUnreferenced([], $this->now));
    }

    public function testTheMetadataSparesHashingWhileItMatchesTheFile(): void
    {
        $image = $this->image(ImageFactory::png(64, 64));
        $this->store->save('brand', 'logo', $image, $this->now);
        $metaPath = $this->root() . '/brand/.variants/logo.meta';
        $meta     = (array) json_decode((string) file_get_contents($metaPath), true);

        // Trusted while size / mtime / ctime / inode match: a planted hash proves the file is not read (JG-L1).
        file_put_contents($metaPath, (string) json_encode(['sha256' => str_repeat('a', 64)] + $meta));
        self::assertSame(str_repeat('a', 64), $this->store->findImage('brand', 'logo', '')[0]->sha256);
        $record = $this->store->find('brand', 'logo', str_repeat('a', 12), 0);
        self::assertNotNull($record);
        self::assertSame('', $this->store->read($record), 'the bytes are still verified before serving');

        foreach ([['mtime' => 1], ['mime' => 'image/webp'], ['sha256' => 'nope'], ['width' => 'x']] as $change) {
            file_put_contents($metaPath, (string) json_encode($change + $meta));
            self::assertSame($image->sha256(), $this->store->findImage('brand', 'logo', '')[0]->sha256, (string) json_encode($change));
        }
        file_put_contents($metaPath, 'not json');
        self::assertSame($image->sha256(), $this->store->findImage('brand', 'logo', '')[0]->sha256);
    }

    public function testSavingTheSameImageAgainDoesNotRewriteIt(): void
    {
        $image = $this->image(ImageFactory::png(40, 40));
        $this->store->save('brand', 'mark', $image, $this->now);
        $path  = $this->root() . '/brand/mark.png';
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

        $root = $this->root();
        self::assertFileDoesNotExist($root . '/brand/logo.png');
        self::assertFileExists($root . '/brand/logo.webp');
        self::assertSame([$root . '/brand/.variants/logo-' . substr($webp->sha256(), 0, 12) . '-32.png'], glob($root . '/brand/.variants/*.png'));
        self::assertNull($this->store->find('brand', 'logo', $png->sha256(), 0), 'the old URL is gone');
        self::assertSame('image/webp', $this->store->find('brand', 'logo', $webp->sha256(), 0)?->mimeType);

        // Outside a unit, a clear deletes the file only while it still holds that image.
        $this->store->orphan('brand', 'logo', $png->sha256(), $this->now);
        self::assertFileExists($root . '/brand/logo.webp');
        $this->store->orphan('brand', 'logo', $webp->sha256(), $this->now);
        self::assertFileDoesNotExist($root . '/brand/logo.webp');
        self::assertSame([], glob($root . '/brand/.variants/*'));
    }

    public function testChangesAreStagedUntilTheOutermostUnitCommits(): void
    {
        $old = $this->image(ImageFactory::png(64, 64), [32]);
        $this->store->save('brand', 'logo', $old, $this->now);
        $root = $this->root();
        $new  = $this->image(ImageFactory::webp(64, 64, 10), [32]);

        // Rolled back: nothing changes on disk, the temporary files are gone (JG-M1).
        $this->store->begin();
        $this->store->save('brand', 'logo', $new, $this->now);
        $this->store->orphan('brand', 'logo', $old->sha256(), $this->now);
        self::assertSame($old->original->bytes, file_get_contents($root . '/brand/logo.png'), 'staged only');
        self::assertNotSame([], glob($root . '/brand/.tmp-*'));
        $this->store->end(false);
        self::assertSame($old->original->bytes, file_get_contents($root . '/brand/logo.png'));
        self::assertFileDoesNotExist($root . '/brand/logo.webp');
        self::assertSame([], $this->temps($root . '/brand'));

        $this->store->begin();
        $this->store->orphan('brand', 'logo', str_repeat('f', 64), $this->now);
        $this->store->end(false);
        self::assertFileExists($root . '/brand/logo.png', 'a rolled-back clear keeps the image');

        // Nested: an inner commit waits for the outer one; the replacement is not undone by its own orphan call.
        $this->store->begin();
        $this->store->begin();
        $this->store->save('brand', 'logo', $new, $this->now);
        $this->store->end(true);
        $this->store->orphan('brand', 'logo', $old->sha256(), $this->now);
        self::assertFileExists($root . '/brand/logo.png');
        $this->store->end(true);
        self::assertFileDoesNotExist($root . '/brand/logo.png');
        self::assertSame('image/webp', $this->store->findImage('brand', 'logo', '')[0]->mimeType);

        // A committed clear deletes the field's file whatever its hash (JG-L3).
        $this->store->begin();
        $this->store->orphan('brand', 'logo', str_repeat('f', 64), $this->now);
        $this->store->end(true);
        self::assertSame([], glob($root . '/brand/logo.*'));
        self::assertSame([], glob($root . '/brand/.variants/*'));

        // kernel.reset discards what an unfinished unit staged.
        $this->store->begin();
        $this->store->save('brand', 'logo', $old, $this->now);
        $this->store->reset();
        self::assertSame([], glob($root . '/brand/logo.*'));
        self::assertSame([], $this->temps($root . '/brand'));
        $this->store->end(true);
    }

    public function testChangesInsideAHostTransactionWaitForItsRealCommit(): void
    {
        $old = $this->image(ImageFactory::png(64, 64));
        $this->store->save('brand', 'logo', $old, $this->now);
        $root = $this->root();
        $new  = $this->image(ImageFactory::png(64, 64, 10));
        $a    = new stdClass();
        $b    = new stdClass();

        // Committed by the bundle, but the host transaction rolls back: discarded.
        $this->store->transactionStarted($a);
        $this->store->begin();
        $this->store->save('brand', 'logo', $new, $this->now);
        $this->store->end(true);
        $this->store->orphan('brand', 'logo', $old->sha256(), $this->now);
        self::assertSame($old->original->bytes, file_get_contents($root . '/brand/logo.png'));
        $this->store->transactionEnded($a, false);
        self::assertSame($old->original->bytes, file_get_contents($root . '/brand/logo.png'));
        self::assertSame([], glob($root . '/brand/.tmp-*'));

        // Applied when its own connection commits; another connection ending changes nothing (JG-L7).
        $this->store->transactionStarted($a);
        $this->store->save('brand', 'logo', $new, $this->now);
        $this->store->transactionEnded($b, false);
        $this->store->savepointCreated($b, 'DOCTRINE_2');
        self::assertSame($old->original->bytes, file_get_contents($root . '/brand/logo.png'));
        $this->store->transactionEnded($a, true);
        self::assertSame($new->original->bytes, file_get_contents($root . '/brand/logo.png'));

        // A clear outside a unit waits as well; kernel.reset forgets everything.
        $this->store->transactionStarted($a);
        $this->store->orphan('brand', 'logo', $new->sha256(), $this->now);
        $this->store->save('brand', 'mark', $old, $this->now);
        $this->store->reset();
        $this->store->transactionEnded($a, true);
        self::assertFileExists($root . '/brand/logo.png');
        self::assertFileDoesNotExist($root . '/brand/mark.png');
        self::assertSame([], glob($root . '/brand/.tmp-*'));
    }

    public function testASavepointRollbackDropsTheChangesQueuedInside(): void
    {
        $old = $this->image(ImageFactory::png(64, 64));
        $this->store->save('brand', 'logo', $old, $this->now);
        $root = $this->root();
        $new  = $this->image(ImageFactory::png(64, 64, 10));
        $c    = new stdClass();

        // Rolled back to the savepoint, then the outer transaction commits: the image is intact (JG-M3).
        $this->store->transactionStarted($c);
        $this->store->savepointCreated($c, 'DOCTRINE_2');
        $this->store->orphan('brand', 'logo', $old->sha256(), $this->now);
        $this->store->savepointCreated($c, 'DOCTRINE_3');
        $this->store->save('brand', 'mark', $new, $this->now);
        $this->store->savepointRolledBack($c, 'doctrine_2');
        $this->store->transactionEnded($c, true);
        self::assertSame($old->original->bytes, file_get_contents($root . '/brand/logo.png'));
        self::assertFileDoesNotExist($root . '/brand/mark.png');
        self::assertSame([], self::temps($root . '/brand'));

        // Released, then committed: applied. Released, then rolled back: dropped.
        $this->store->transactionStarted($c);
        $this->store->savepointCreated($c, 'DOCTRINE_2');
        $this->store->save('brand', 'logo', $new, $this->now);
        $this->store->savepointReleased($c, 'DOCTRINE_2');
        $this->store->savepointReleased($c, 'UNKNOWN');
        $this->store->transactionEnded($c, true);
        self::assertSame($new->original->bytes, file_get_contents($root . '/brand/logo.png'));

        $this->store->transactionStarted($c);
        $this->store->savepointCreated($c, 'DOCTRINE_2');
        $this->store->save('brand', 'logo', $old, $this->now);
        $this->store->savepointReleased($c, 'DOCTRINE_2');
        $this->store->transactionEnded($c, false);
        self::assertSame($new->original->bytes, file_get_contents($root . '/brand/logo.png'));

        // A platform that cannot release savepoints re-creates the same name: the open level counts as committed.
        $this->store->transactionStarted($c);
        $this->store->savepointCreated($c, 'DOCTRINE_2');
        $this->store->save('brand', 'logo', $old, $this->now);
        $this->store->savepointCreated($c, 'DOCTRINE_2');
        $this->store->savepointRolledBack($c, 'DOCTRINE_2');
        $this->store->transactionEnded($c, true);
        self::assertSame($old->original->bytes, file_get_contents($root . '/brand/logo.png'));
    }

    public function testTheBaseDirectoryIsRefusedUnderAForbiddenRoot(): void
    {
        mkdir($this->dir . '/public', 0o755, true);
        mkdir($this->dir . '/real', 0o755, true);
        symlink($this->dir . '/public', $this->dir . '/linked');
        // 1.7.1: checked when the store is built (an %env(...)% directory is only known then), also before it exists.
        foreach (['/x/../public/files', '//public/files', '/./public/files', '/public', '/linked/new/dir', '/real/../linked/files'] as $suffix) {
            $this->assertRefused(fn (): FilesystemSettingFileStore => new FilesystemSettingFileStore($this->dir . $suffix, [$this->dir . '/public']), 'under the public web root');
        }
        self::assertDirectoryDoesNotExist($this->dir . '/public/files');
        self::assertDirectoryDoesNotExist($this->dir . '/public/new');
        // A root that is not a local path (an empty env value of brand.public_dir) is not a root at all.
        new FilesystemSettingFileStore($this->dir . '/files', ['', $this->dir . '/public']);

        // And again before the first mkdir(): a symlink into the public root created after the store was built.
        $store = new FilesystemSettingFileStore($this->dir . '/later/files', [$this->dir . '/public']);
        symlink($this->dir . '/public', $this->dir . '/later');
        $this->assertRefused(fn () => $store->save('brand', 'logo', $this->image(ImageFactory::png(8, 8)), $this->now), 'under the public web root');
        self::assertDirectoryDoesNotExist($this->dir . '/public/files');
    }

    public function testTheBaseDirectoryMustBeALocalPath(): void
    {
        foreach (['', '   ', 's3://bucket/files', "files\0x", 'phar://app.phar/files'] as $directory) {
            $this->assertRefused(static fn (): FilesystemSettingFileStore => new FilesystemSettingFileStore($directory), 'must be a local directory path');
        }
    }

    public function testVariantsDirectoriesIgnoreThemselvesInGit(): void
    {
        $this->store->save('brand', 'logo', $this->image(ImageFactory::png(16, 16), [8]), $this->now);
        $ignore = $this->root() . '/brand/.variants/.gitignore';
        self::assertSame(FilesystemSettingFileStore::GITIGNORE_CONTENT, file_get_contents($ignore));
        self::assertStringEndsWith("\n*\n", FilesystemSettingFileStore::GITIGNORE_CONTENT);

        // Never overwritten (the host may have its own), never removed by the purge.
        file_put_contents($ignore, "*\n!keep\n");
        $this->store->save('brand', 'logo', $this->image(ImageFactory::png(16, 16, 10), [8]), $this->now);
        $this->store->purge($this->now, static fn (): array => []);
        self::assertSame("*\n!keep\n", file_get_contents($ignore));
        self::assertNotNull($this->store->find('brand', 'logo', $this->image(ImageFactory::png(16, 16, 10))->sha256(), 0));
    }

    public function testAFailedRenameAfterTheCommitKeepsThePreviousFile(): void
    {
        $webp = $this->image(ImageFactory::webp(64, 64, 10));
        $this->store->save('brand', 'logo', $webp, $this->now);
        $root = $this->root();

        $this->store->begin();
        $this->store->save('brand', 'logo', $this->image(ImageFactory::png(64, 64)), $this->now);
        mkdir($root . '/brand/logo.png');
        $this->store->end(true);
        self::assertFileExists($root . '/brand/logo.webp', 'not removed: the new file is not in place');
        self::assertSame([], glob($root . '/brand/.tmp-*'));

        mkdir($this->dir . '/elsewhere');
        $this->store->begin();
        $this->store->save('brand', 'mark', $this->image(ImageFactory::png(64, 64)), $this->now);
        symlink($this->dir . '/elsewhere/mark.png', $root . '/brand/mark.png');
        $this->store->end(true);
        self::assertFileDoesNotExist($this->dir . '/elsewhere/mark.png', 'never written through a symlink');
        self::assertSame([], glob($root . '/brand/.tmp-*'));
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
            self::assertNull($this->store->storedPath('brand', $field), $field);
        }
        self::assertNull($this->store->find('brand', 'g', '000000000000', 0));
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
        self::assertSame([], glob($root . '/shop/.tmp-*'), 'the staged original is removed');

        mkdir($root . '/blog/logo.png', 0o755, true);
        $this->assertRefused(fn () => $this->store->save('blog', 'logo', $image, $this->now), 'could not write');

        $this->assertRefused(fn () => $this->store->save('brand', '../logo', $image, $this->now), 'invalid section / field');

        // A metadata path that cannot be written is ignored (the file is then hashed on lookup).
        mkdir($root . '/news/.variants/logo.meta', 0o755, true);
        $this->store->save('news', 'logo', $image, $this->now);
        self::assertSame($image->sha256(), $this->store->findImage('news', 'logo', '')[0]->sha256);
    }

    public function testAnUnusableDirectoryIsAClearError(): void
    {
        mkdir($this->dir, 0o755, true);
        file_put_contents($this->dir . '/files', 'not a directory');
        $this->assertRefused(fn () => $this->store->save('brand', 'logo', $this->image(ImageFactory::png(8, 8)), $this->now), 'cannot create the directory');

        $store = new FilesystemSettingFileStore($this->dir . '/missing');
        self::assertNull($store->storedPath('brand', 'logo'));
        self::assertSame([], $store->findImage('brand', 'logo', ''));
        self::assertSame(0, $store->purge($this->now, static fn (): array => []));
    }

    public function testPurgeRemovesStaleVariantsMetadataAndOldTemporaryFilesButNeverOriginals(): void
    {
        $image = $this->image(ImageFactory::png(64, 64), [32]);
        $this->store->save('brand', 'logo', $image, $this->now);
        $root     = $this->root();
        $variants = $root . '/brand/.variants';
        file_put_contents($variants . '/logo-ffffffffffff-32.png', 'stale');
        file_put_contents($variants . '/gone-ffffffffffff-32.png', 'no original');
        file_put_contents($variants . '/gone.meta', '{}');
        file_put_contents($variants . '/README', 'left alone');
        file_put_contents($variants . '/.tmp-abc', 'crashed write');
        file_put_contents($root . '/brand/.tmp-def', 'crashed write');
        touch($variants . '/.tmp-abc', time() - 7200);
        touch($root . '/brand/.tmp-def', time() - 7200);
        file_put_contents($root . '/brand/.tmp-live', 'a transaction in flight');
        file_put_contents($root . '/brand/unseeded.png', ImageFactory::png(8, 8));
        mkdir($root . '/Not-A-Section', 0o755);
        mkdir($root . '/empty', 0o755);
        mkdir($this->dir . '/elsewhere', 0o755);
        file_put_contents($this->dir . '/elsewhere/.tmp-x', 'outside');
        symlink($this->dir . '/elsewhere', $root . '/linked');

        self::assertSame(5, $this->store->purge($this->now, static fn (): array => [], true));
        self::assertFileExists($variants . '/logo-ffffffffffff-32.png', 'dry run');
        self::assertSame(5, $this->store->purge($this->now, static fn (): array => []));
        self::assertFileDoesNotExist($variants . '/logo-ffffffffffff-32.png');
        self::assertFileDoesNotExist($variants . '/gone-ffffffffffff-32.png');
        self::assertFileDoesNotExist($variants . '/gone.meta');
        self::assertFileDoesNotExist($root . '/brand/.tmp-def');
        self::assertFileExists($root . '/brand/.tmp-live', 'a fresh temporary file may belong to a transaction');
        self::assertFileExists($variants . '/README');
        self::assertFileExists($variants . '/logo.meta');
        self::assertFileExists($root . '/brand/unseeded.png', 'an original is never purged');
        self::assertFileExists($root . '/brand/logo.png');
        self::assertFileExists($variants . '/logo-' . substr($image->sha256(), 0, 12) . '-32.png');
        self::assertFileExists($this->dir . '/elsewhere/.tmp-x');
        unlink($root . '/brand/.tmp-live');
    }

    private function root(): string
    {
        return (string) realpath($this->dir . '/files');
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
