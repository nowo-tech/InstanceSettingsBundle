<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\File;

use GdImage;
use Nowo\InstanceSettingsBundle\File\ImageProcessor;
use Nowo\InstanceSettingsBundle\File\ImageRefusedException;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function getimagesizefromstring;
use function hash;
use function imagecolorallocate;
use function imagecolorat;
use function imagecreate;
use function imagecreatefromstring;
use function imagesx;
use function str_contains;
use function str_repeat;
use function substr;

/**
 * Sniffing, limits and re-encoding of image uploads (spec 220 §5.2–§5.4, S2 test plan).
 */
final class ImageProcessorTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function acceptedImages(): iterable
    {
        yield 'png' => [ImageFactory::png(), 'image/png'];
        yield 'jpeg' => [ImageFactory::jpeg(), 'image/jpeg'];
        yield 'still webp' => [ImageFactory::webp(), 'image/webp'];
    }

    #[DataProvider('acceptedImages')]
    public function testAcceptsEachTypeAndReEncodesIt(string $bytes, string $mime): void
    {
        $processed = (new ImageProcessor())->process(new ImageSpec(), $bytes);

        self::assertSame($mime, $processed->original->mimeType);
        self::assertSame(64, $processed->original->width);
        self::assertSame(64, $processed->original->height);
        self::assertSame(hash('sha256', $bytes), $processed->sourceSha256);
        self::assertSame(hash('sha256', $processed->original->bytes), $processed->sha256());
        self::assertSame($mime, ImageProcessor::sniff($processed->original->bytes));
        self::assertSame([], $processed->variants);
    }

    public function testReEncodingIsDeterministic(): void
    {
        $processor = new ImageProcessor();
        $png       = ImageFactory::png();

        self::assertSame($processor->process(new ImageSpec(), $png)->sha256(), $processor->process(new ImageSpec(), $png)->sha256());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function refusedContent(): iterable
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>';
        yield 'svg renamed .png' => [$svg, ImageRefusedException::INVALID_IMAGE];
        yield 'svg behind a BOM' => ["\xEF\xBB\xBF" . $svg, ImageRefusedException::INVALID_IMAGE];
        yield 'gif renamed .png' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00\xFF\xFF\xFF\x00\x00\x00!\xF9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;", ImageRefusedException::INVALID_IMAGE];
        yield 'html' => ['<html><body><script>alert(1)</script></body></html>', ImageRefusedException::INVALID_IMAGE];
        yield 'pdf' => ["%PDF-1.7\n1 0 obj <<>> endobj", ImageRefusedException::INVALID_IMAGE];
        yield 'bmp' => ['BM' . str_repeat("\0", 60), ImageRefusedException::INVALID_IMAGE];
        // getimagesize() reads 'AAAA' as a huge IHDR: refused before any decoding.
        yield 'png magic, garbage body' => ["\x89PNG\r\n\x1a\n" . str_repeat('A', 64), ImageRefusedException::TOO_MANY_PIXELS];
        yield 'jpeg magic, garbage body' => ["\xFF\xD8\xFF" . str_repeat('A', 64), ImageRefusedException::INVALID_IMAGE];
        yield 'png magic with a jpeg body' => ["\x89PNG\r\n\x1a\n" . substr(ImageFactory::jpeg(), 0), ImageRefusedException::TOO_MANY_PIXELS];
        yield 'png magic with a tiny jpeg-like body' => ["\x89PNG\r\n\x1a\n\0\0\0\x0DIHDR\0\0\0\x01\0\0\0\x01\x08\x02\0\0\0\xFF\xD8\xFF\xE0", ImageRefusedException::INVALID_IMAGE];
        yield 'riff that is not webp' => ['RIFF' . "\x10\0\0\0" . 'WAVEfmt ' . str_repeat("\0", 16), ImageRefusedException::INVALID_IMAGE];
        yield 'png header with a broken body' => [ImageFactory::pngHeader(32, 32), ImageRefusedException::INVALID_IMAGE];
        yield 'zero dimensions' => [ImageFactory::pngHeader(0, 0), ImageRefusedException::INVALID_IMAGE];
        yield 'empty' => ['', ImageRefusedException::EMPTY];
    }

    #[DataProvider('refusedContent')]
    public function testRefusesAnythingThatIsNotAnAcceptedRaster(string $bytes, string $reason): void
    {
        $this->assertRefused($reason, new ImageSpec(), $bytes);
    }

    public function testPolyglotTrailersAreDroppedByTheReEncode(): void
    {
        $processor = new ImageProcessor();
        $zip       = "PK\x03\x04" . str_repeat('Z', 40) . 'evil.php<?php system($_GET[1]); ?>';
        $html      = '<script>alert(document.domain)</script>';

        $png = $processor->process(new ImageSpec(), ImageFactory::png() . $zip);
        self::assertStringNotContainsString('PK' . "\x03\x04", $png->original->bytes);
        self::assertStringNotContainsString('<?php', $png->original->bytes);

        $jpeg = $processor->process(new ImageSpec(), ImageFactory::jpeg() . $html);
        self::assertStringNotContainsString('<script>', $jpeg->original->bytes);
        self::assertNotSame($jpeg->sourceSha256, $jpeg->sha256(), 'stored hash is the hash of the re-encoded bytes');
    }

    public function testDecompressionBombIsRefusedFromTheHeaderBeforeDecoding(): void
    {
        $bomb = ImageFactory::pngHeader(30_000, 30_000);
        self::assertSame([30_000, 30_000], [getimagesizefromstring($bomb)[0] ?? null, getimagesizefromstring($bomb)[1] ?? null]);

        $this->assertRefused(ImageRefusedException::TOO_MANY_PIXELS, new ImageSpec(maxWidth: 4096, maxHeight: 4096), $bomb);
        // Within the pixel cap but above the field's limits.
        $this->assertRefused(ImageRefusedException::DIMENSIONS_TOO_LARGE, new ImageSpec(maxWidth: 2000, maxHeight: 2000), ImageFactory::pngHeader(3000, 1000));
    }

    public function testThePixelCapMatchesTheDefaultMaxDimensions(): void
    {
        // IO-I2: 4096 × 4096 (the default maximum) passes the pixel cap; one more column does not.
        self::assertSame(4096, (new ImageSpec(minWidth: 4096, minHeight: 4096))->minWidth);
        $this->assertRefused(ImageRefusedException::TOO_MANY_PIXELS, new ImageSpec(maxWidth: 5000), ImageFactory::pngHeader(4097, 4096));
        // The header-only 4096 × 4096 PNG gets past the cap, the field limits and an unlimited budget: GD refuses its
        // empty pixel data, not the dimensions.
        $this->assertRefusedWith(new ImageProcessor('-1'), ImageRefusedException::INVALID_IMAGE, new ImageSpec(), ImageFactory::pngHeader(4096, 4096));
    }

    public function testMemoryBudgetRefusesBeforeDecoding(): void
    {
        $tight = new ImageProcessor('1M');
        $this->assertRefusedWith($tight, ImageRefusedException::TOO_MANY_PIXELS, new ImageSpec(), ImageFactory::png(600, 600));

        self::assertTrue(ImageProcessor::fitsInMemory(1_000_000, '-1', 10));
        self::assertTrue(ImageProcessor::fitsInMemory(1_000_000, '', 10));
        self::assertTrue(ImageProcessor::fitsInMemory(100, '1G', 10));
        self::assertTrue(ImageProcessor::fitsInMemory(100, '2048K', 10));
        self::assertFalse(ImageProcessor::fitsInMemory(100, '500', 10));
        self::assertFalse(ImageProcessor::fitsInMemory(1_500_000, '16M', 10, ImageProcessor::JPEG_EXTRA_BYTES_PER_PIXEL));
        self::assertTrue(ImageProcessor::fitsInMemory(1_500_000, '16M', 10));
    }

    public function testFieldLimits(): void
    {
        $this->assertRefused(ImageRefusedException::TOO_LARGE, new ImageSpec(maxBytes: 100), ImageFactory::png());
        $this->assertRefused(ImageRefusedException::DIMENSIONS_TOO_SMALL, new ImageSpec(minWidth: 128), ImageFactory::png());
        $this->assertRefused(ImageRefusedException::DIMENSIONS_TOO_SMALL, new ImageSpec(minHeight: 128), ImageFactory::png());
        $this->assertRefused(ImageRefusedException::DIMENSIONS_TOO_LARGE, new ImageSpec(maxHeight: 32), ImageFactory::png());
        $this->assertRefused(ImageRefusedException::NOT_SQUARE, new ImageSpec(square: true), ImageFactory::png(64, 60));
        $this->assertRefused(ImageRefusedException::TYPE_NOT_ALLOWED, new ImageSpec(types: ['image/jpeg']), ImageFactory::png());

        // Square within 2 %.
        self::assertSame(64, (new ImageProcessor())->process(new ImageSpec(square: true), ImageFactory::png(64, 63))->original->width);
    }

    public function testWrongDeclaredExtensionOrMimeTypeIsIrrelevant(): void
    {
        // The processor only ever sees bytes: a JPEG "named" logo.png with type image/png is a JPEG.
        self::assertSame('image/jpeg', (new ImageProcessor())->process(new ImageSpec(), ImageFactory::jpeg())->original->mimeType);
    }

    public function testAnimatedWebpIsRefusedAndApngKeepsOneFrame(): void
    {
        $this->assertRefused(ImageRefusedException::ANIMATED, new ImageSpec(), ImageFactory::animatedWebp());
        self::assertTrue(ImageProcessor::isAnimatedWebp(ImageFactory::animatedWebp(true, false)), 'VP8X animation flag alone');
        self::assertTrue(ImageProcessor::isAnimatedWebp(ImageFactory::animatedWebp(false, true)), 'ANIM chunk alone');
        self::assertFalse(ImageProcessor::isAnimatedWebp(ImageFactory::animatedWebp(false, false)));
        self::assertFalse(ImageProcessor::isAnimatedWebp(ImageFactory::webp()));

        $apng = (new ImageProcessor())->process(new ImageSpec(), ImageFactory::apng());
        self::assertSame('image/png', $apng->original->mimeType);
        self::assertStringNotContainsString('acTL', $apng->original->bytes);
    }

    public function testExifIsStrippedAndTheJpegTurnedUpright(): void
    {
        $source = ImageFactory::jpegWithExif(6, 80, 40);
        self::assertStringContainsString('SECRET-EXIF-PAYLOAD', $source);
        self::assertSame(6, ImageProcessor::jpegOrientation($source));

        $processed = (new ImageProcessor())->process(new ImageSpec(), $source);
        self::assertStringNotContainsString('GPSLatitude', $processed->original->bytes);
        self::assertStringNotContainsString('SECRET-EXIF-PAYLOAD', $processed->original->bytes);
        self::assertFalse(str_contains($processed->original->bytes, 'Exif'));
        self::assertSame([40, 80], [$processed->original->width, $processed->original->height], 'rotated a quarter turn');

        // The field limits apply to the upright dimensions: 80 × 40 stored sideways is 40 wide.
        $this->assertRefused(ImageRefusedException::DIMENSIONS_TOO_LARGE, new ImageSpec(maxHeight: 60), $source);
    }

    /**
     * @return iterable<string, array{0: int, 1: array{0: int, 1: int}}>
     */
    public static function orientations(): iterable
    {
        yield 'mirror' => [2, [80, 40]];
        yield '180' => [3, [80, 40]];
        yield 'flip' => [4, [80, 40]];
        yield 'transpose' => [5, [40, 80]];
        yield 'transverse' => [7, [40, 80]];
        yield '270' => [8, [40, 80]];
    }

    /**
     * @param array{0: int, 1: int} $size
     */
    #[DataProvider('orientations')]
    public function testEveryExifOrientation(int $orientation, array $size): void
    {
        $processed = (new ImageProcessor())->process(new ImageSpec(), ImageFactory::jpegWithExif($orientation, 80, 40, $orientation % 2 === 0));

        self::assertSame($size, [$processed->original->width, $processed->original->height]);
    }

    public function testOrientationParserIsRobust(): void
    {
        self::assertSame(1, ImageProcessor::jpegOrientation(ImageFactory::jpeg()));
        self::assertSame(1, ImageProcessor::jpegOrientation(ImageFactory::jpegWithExif(9)), 'out of range');
        self::assertSame(1, ImageProcessor::jpegOrientation("\xFF\xD8\xFF\xE1\x00\x10Exif\0\0MM\0*"), 'truncated TIFF');
        self::assertSame(1, ImageProcessor::jpegOrientation("\xFF\xD8\xFF\xE1\x00\x01"), 'bad segment size');
        // An IFD claiming more entries than the segment holds.
        self::assertSame(1, ImageProcessor::jpegOrientation("\xFF\xD8\xFF\xE1\x00\x18Exif\0\0MM\0*\0\0\0\x08\0\x09\x01\x12"));
        // An IFD without an orientation entry.
        self::assertSame(1, ImageProcessor::jpegOrientation("\xFF\xD8\xFF\xE1\x00\x22Exif\0\0MM\0*\0\0\0\x08\0\x01\x01\x0F\0\x03\0\0\0\x01\0\x01\0\0\0\0\0\0"));
    }

    public function testVariantsArePngLargestFirstAndNeverUpscaled(): void
    {
        $processed = (new ImageProcessor())->process(new ImageSpec(variants: [16, 512, 32]), ImageFactory::jpeg(128, 64));

        self::assertSame([512, 32, 16], array_keys($processed->variants));
        self::assertSame([128, 64], [$processed->variants[512]->width, $processed->variants[512]->height], 'not upscaled');
        self::assertSame([32, 16], [$processed->variants[32]->width, $processed->variants[32]->height]);
        self::assertSame([16, 8], [$processed->variants[16]->width, $processed->variants[16]->height]);
        foreach ($processed->variants as $variant) {
            self::assertSame('image/png', $variant->mimeType);
            self::assertSame('image/png', ImageProcessor::sniff($variant->bytes));
        }
    }

    public function testAlphaIsKeptInPngAndWebp(): void
    {
        foreach ([ImageFactory::png(), ImageFactory::webp()] as $bytes) {
            $image = imagecreatefromstring((new ImageProcessor())->process(new ImageSpec(), $bytes)->original->bytes);
            self::assertInstanceOf(GdImage::class, $image);
            self::assertGreaterThan(0, (imagecolorat($image, 1, 1) >> 24) & 0x7F, 'semi-transparent pixel kept');
            self::assertSame(64, imagesx($image));
        }
    }

    public function testAnEncoderFailureRefusesTheImage(): void
    {
        $palette = imagecreate(4, 4);
        self::assertInstanceOf(GdImage::class, $palette);
        imagecolorallocate($palette, 0, 0, 0);

        $encode = new ReflectionMethod(ImageProcessor::class, 'encode');
        $this->expectException(ImageRefusedException::class);
        // GD cannot write a palette image as WebP.
        @$encode->invoke(new ImageProcessor(), $palette, 'image/webp');
    }

    private function assertRefused(string $reason, ImageSpec $spec, string $bytes): void
    {
        $this->assertRefusedWith(new ImageProcessor(), $reason, $spec, $bytes);
    }

    private function assertRefusedWith(ImageProcessor $processor, string $reason, ImageSpec $spec, string $bytes): void
    {
        try {
            $processor->process($spec, $bytes);
            self::fail('Expected the image to be refused with ' . $reason);
        } catch (ImageRefusedException $e) {
            self::assertSame($reason, $e->reason());
        }
    }
}
