<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\File;

use Nowo\InstanceSettingsBundle\File\PathGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function bin2hex;
use function getcwd;
use function mkdir;
use function random_bytes;
use function realpath;
use function symlink;
use function sys_get_temp_dir;

/**
 * Paths that do not exist yet are resolved lexically from their nearest existing ancestor (audit JG-L6).
 */
final class PathGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-is-pg-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/public', 0o755, true);
        mkdir($this->dir . '/src', 0o755, true);
        symlink($this->dir . '/public', $this->dir . '/linked');
        $this->dir = (string) realpath($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testEverySpellingOfAPathUnderTheRootIsUnderIt(): void
    {
        $public = $this->dir . '/public';
        foreach (['/public/files', '/src/../public/files', '//public/files', '/./public/files', '/linked/sub/files', '/public', '/missing/../public/x', '\\public\\files'] as $spelling) {
            self::assertTrue(PathGuard::isUnder($this->dir . $spelling, $public), $spelling);
        }
        foreach (['/config/files', '/publicity/files', '/src/files'] as $spelling) {
            self::assertFalse(PathGuard::isUnder($this->dir . $spelling, $public), $spelling);
        }
    }

    public function testResolveForms(): void
    {
        self::assertSame($this->dir . '/public/a/b/', PathGuard::resolve($this->dir . '/linked/a/./b//'));
        self::assertSame('/', PathGuard::resolve('/../..'));
        self::assertSame(getcwd() . '/nowhere-x/', PathGuard::resolve('nowhere-x'));
    }

    public function testAbsoluteAndLocalPaths(): void
    {
        foreach (['/srv/app', 'C:\\app', 'c:/app', '\\\\server\\share'] as $path) {
            self::assertTrue(PathGuard::isAbsolute($path), $path);
        }
        foreach (['var/files', './files', '../files', 'files', 'C:files', '\\files', ''] as $path) {
            self::assertFalse(PathGuard::isAbsolute($path), $path);
        }
        self::assertTrue(PathGuard::isLocalPath('var/files'));
        foreach (['', ' ', 's3://x', "a\0b"] as $path) {
            self::assertFalse(PathGuard::isLocalPath($path));
        }
    }
}
