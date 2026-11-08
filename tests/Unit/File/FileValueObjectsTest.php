<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\File;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingFile;
use Nowo\InstanceSettingsBundle\File\EncodedImage;
use Nowo\InstanceSettingsBundle\File\GdSupport;
use Nowo\InstanceSettingsBundle\File\ImageRefusedException;
use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\File\SettingFileUrls;
use Nowo\InstanceSettingsBundle\File\StoredSettingFile;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function file_put_contents;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const UPLOAD_ERR_PARTIAL;

final class FileValueObjectsTest extends TestCase
{
    public function testImageSpecDefaultsAndNormalization(): void
    {
        $spec = new ImageSpec(['image/png', 'image/png'], variants: [16, 192, 16, 512]);

        self::assertSame(['image/png'], $spec->types);
        self::assertSame([512, 192, 16], $spec->variants);
        self::assertTrue($spec->hasVariant(192));
        self::assertFalse($spec->hasVariant(48));
        self::assertFalse($spec->public);
        self::assertSame(ImageSpec::DEFAULT_MAX_BYTES, (new ImageSpec())->maxBytes);
    }

    /**
     * @return iterable<string, array{0: callable(): ImageSpec}>
     */
    public static function invalidSpecs(): iterable
    {
        yield 'no type' => [static fn (): ImageSpec => new ImageSpec([])];
        yield 'svg' => [static fn (): ImageSpec => new ImageSpec(['image/svg+xml'])];
        yield 'gif' => [static fn (): ImageSpec => new ImageSpec(['image/png', 'image/gif'])];
        yield 'zero bytes' => [static fn (): ImageSpec => new ImageSpec(maxBytes: 0)];
        yield 'above the 5 MiB ceiling' => [static fn (): ImageSpec => new ImageSpec(maxBytes: ImageSpec::MAX_BYTES_CEILING + 1)];
        yield 'min above max' => [static fn (): ImageSpec => new ImageSpec(maxWidth: 10, minWidth: 20)];
        yield 'zero min' => [static fn (): ImageSpec => new ImageSpec(minHeight: 0)];
        yield 'min above the pixel cap' => [static fn (): ImageSpec => new ImageSpec(maxWidth: 5000, maxHeight: 5000, minWidth: 4500, minHeight: 4500)];
        yield 'zero variant' => [static fn (): ImageSpec => new ImageSpec(variants: [0])];
        yield 'huge variant' => [static fn (): ImageSpec => new ImageSpec(variants: [ImageSpec::MAX_VARIANT + 1])];
        yield 'non-int variant' => [static fn (): ImageSpec => new ImageSpec(variants: ['192'])]; // @phpstan-ignore argument.type
        yield 'too many variants' => [static fn (): ImageSpec => new ImageSpec(variants: [1, 2, 3, 4, 5, 6, 7, 8, 9])];
    }

    /**
     * @param callable(): ImageSpec $build
     */
    #[DataProvider('invalidSpecs')]
    public function testImageSpecRefusesImpossibleRules(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build();
    }

    public function testRefusalMessagesNeverCarryTheFile(): void
    {
        $e = new ImageRefusedException(ImageRefusedException::NOT_SQUARE);
        self::assertSame('not_square', $e->reason());
        self::assertSame('The image must be square.', $e->describe());
        self::assertSame('The file is not a valid PNG, JPEG or WebP image.', (new ImageRefusedException('whatever'))->describe());
    }

    public function testGdSupportFailsClosed(): void
    {
        self::assertSame([], GdSupport::missing(['PNG Support' => true, 'JPEG Support' => true, 'WebP Support' => true]));
        self::assertSame(['WebP Support'], GdSupport::missing(['PNG Support' => true, 'JPEG Support' => true, 'WebP Support' => false]));
        GdSupport::assertAvailable();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('missing: PNG Support, JPEG Support, WebP Support');
        GdSupport::assertAvailable([]);
    }

    public function testImageUploadReadsWithAHardCap(): void
    {
        self::assertSame('abc', ImageUpload::fromBytes('abc')->read(3));

        $path = (string) tempnam(sys_get_temp_dir(), 'isf');
        file_put_contents($path, str_repeat('x', 10));
        try {
            self::assertSame(str_repeat('x', 10), ImageUpload::fromPath($path)->read(10));
            self::assertSame(str_repeat('x', 10), ImageUpload::fromUploadedFile(new UploadedFile($path, '../../etc/passwd.png', 'image/png', null, true))->read(10));
            $this->assertReadRefused(ImageUpload::fromPath($path), 9, ImageRefusedException::TOO_LARGE);
            $this->assertReadRefused(ImageUpload::fromBytes('abcd'), 3, ImageRefusedException::TOO_LARGE);
            $this->assertReadRefused(ImageUpload::fromUploadedFile(new UploadedFile($path, 'a.png', 'image/png', UPLOAD_ERR_PARTIAL, true)), 10, ImageRefusedException::UPLOAD_FAILED);
        } finally {
            unlink($path);
        }
        $this->assertReadRefused(ImageUpload::fromPath('/nonexistent/x.png'), 10, ImageRefusedException::UPLOAD_FAILED);
    }

    public function testEncodedImage(): void
    {
        $image = new EncodedImage('bytes', 'image/png', 2, 3);
        self::assertSame(5, $image->byteSize());
        self::assertSame('277089d91c0bdf4f2e6862ba7e4a07605119431f5d13f726dd352b06f1b206a9', $image->sha256());
    }

    public function testStoredSettingFileUrls(): void
    {
        $file = new StoredSettingFile('brand', 'logo', str_repeat('a', 64), 'image/png', 10, 20, 30, true, [0 => '/f/a.png', 192 => '/f/a-192.png', 32 => null]);

        self::assertSame('/f/a.png', $file->url());
        self::assertSame('/f/a-192.png', $file->url(192));
        self::assertNull($file->url(32));
        self::assertNull($file->url(48));
        self::assertSame([192, 32], $file->variants());
    }

    public function testSettingFileUrls(): void
    {
        self::assertNull((new SettingFileUrls())->path('brand', 'logo', str_repeat('a', 64), 'image/png'));

        $routes = new RouteCollection();
        $routes->add(InstanceSettingsRoutes::FILE, new Route('/_settings-file/{section}/{field}/{file}.{ext}'));
        $urls = new SettingFileUrls(new UrlGenerator($routes, new RequestContext()));
        $sha  = '0123456789abcdef' . str_repeat('0', 48);

        self::assertSame('/_settings-file/brand/logo/0123456789ab.jpg', $urls->path('brand', 'logo', $sha, 'image/jpeg'));
        self::assertSame('/_settings-file/brand/logo/0123456789ab.webp', $urls->path('brand', 'logo', $sha, 'image/webp'));
        self::assertSame('/_settings-file/brand/logo/0123456789ab-192.png', $urls->path('brand', 'logo', $sha, 'image/webp', 192));
        self::assertSame('/_settings-file/brand/logo/0123456789ab.png', $urls->path('brand', 'logo', $sha, 'image/unknown'));

        self::assertNull((new SettingFileUrls(new UrlGenerator(new RouteCollection(), new RequestContext())))->path('brand', 'logo', $sha, 'image/png'), 'route not mounted');
    }

    public function testSettingFileEntity(): void
    {
        $at   = new DateTimeImmutable('2026-11-08 10:00:00');
        $file = new SettingFile('brand', 'logo', str_repeat('b', 64), 0, 'image/png', 4, 5, 6, 'bytes', $at);

        self::assertNull($file->getId());
        self::assertSame(['brand', 'logo', str_repeat('b', 64), 0, 'image/png', 4, 5, 6, 'bytes', $at, null], [
            $file->getSection(), $file->getName(), $file->getSha256(), $file->getVariant(), $file->getMimeType(), $file->getWidth(), $file->getHeight(), $file->getByteSize(), $file->getContent(), $file->getCreatedAt(), $file->getOrphanedAt(),
        ]);
    }

    private function assertReadRefused(ImageUpload $upload, int $max, string $reason): void
    {
        try {
            $upload->read($max);
            self::fail('Expected a refusal');
        } catch (ImageRefusedException $e) {
            self::assertSame($reason, $e->reason());
        }
    }
}
