<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\File;

use Nowo\InstanceSettingsBundle\File\ImageMetadataStripper;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function chr;
use function crc32;
use function pack;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Lossless metadata / trailing-data removal (1.7.1, audit JG-L3 without generational loss).
 */
final class ImageMetadataStripperTest extends TestCase
{
    public function testCleanFilesComeBackByteForByte(): void
    {
        foreach (['image/png' => ImageFactory::png(), 'image/jpeg' => ImageFactory::jpeg(), 'image/webp' => ImageFactory::webp()] as $mime => $bytes) {
            self::assertSame($bytes, ImageMetadataStripper::strip($mime, $bytes), $mime);
        }
        self::assertNull(ImageMetadataStripper::strip('image/gif', 'GIF89a'));
    }

    public function testJpegMetadataAndTrailersAreDropped(): void
    {
        $clean = ImageFactory::jpeg(80, 40);
        $dirty = ImageFactory::jpegWithExif(1, 80, 40);
        // XMP (APP1), ICC (APP2), Photoshop (APP13), a JFIF with a thumbnail, a JFXX, a foreign comment: after SOI.
        $meta = self::seg(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>GPS</x:xmpmeta>")
            . self::seg(0xE2, "ICC_PROFILE\0\x01\x01" . str_repeat('p', 40))
            . self::seg(0xED, 'Photoshop 3.0' . "\0")
            . self::seg(0xE0, "JFIF\0\x01\x01\0\0\x01\0\x01\x01\x01" . 'rgb')
            . self::seg(0xE0, "JFXX\0\x10thumb")
            . self::seg(0xEE, 'Adobe-too-long-segment')
            . self::seg(0xFE, '<script>alert(1)</script>');
        $dirty = substr($dirty, 0, 2) . $meta . substr($dirty, 2) . '<html><script>alert(1)</script>';

        $stripped = ImageMetadataStripper::strip('image/jpeg', $dirty);
        self::assertSame($clean, $stripped, 'exactly the GD file without the inserted segments and trailer');
        self::assertSame($stripped, ImageMetadataStripper::strip('image/jpeg', $stripped), 'idempotent');
    }

    public function testJpegDecodingSegmentsAndScanDataAreKept(): void
    {
        $adobe = self::seg(0xEE, 'Adobe' . "\0\x64\0\0\0\0\x01");
        $sof   = self::seg(0xC0, "\x08\0\x01\0\x01\x01\x01\x11\0");
        $scan  = self::seg(0xDA, "\x01\x01\0\0\x3F\0") . "ab\xFF\x00cd\xFF\xD0ef\xFF";
        // A standalone marker (TEM) and fill bytes between segments are understood; fill bytes are not copied.
        $jpeg = "\xFF\xD8" . $adobe . "\xFF\x01\xFF\xFF" . $sof . $scan . "\xFF\xD9" . 'trailer';

        self::assertSame("\xFF\xD8" . $adobe . "\xFF\x01" . $sof . $scan . "\xFF\xD9", ImageMetadataStripper::strip('image/jpeg', $jpeg));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedJpegs(): iterable
    {
        $sos = self::seg(0xDA, "\x01\x01\0\0\x3F\0") . 'data';
        yield 'no SOI' => ['JFIF'];
        yield 'garbage between segments' => ["\xFF\xD8" . 'xx' . $sos . "\xFF\xD9"];
        yield 'ends in fill bytes' => ["\xFF\xD8\xFF\xFF"];
        yield 'ends after a marker byte' => ["\xFF\xD8\xFF\xE1\x00"];
        yield 'stuffed zero as a marker' => ["\xFF\xD8\xFF\x00\x00\x04"];
        yield 'second SOI' => ["\xFF\xD8\xFF\xD8\x00\x04"];
        yield 'segment length below 2' => ["\xFF\xD8\xFF\xE1\x00\x01"];
        yield 'segment longer than the file' => ["\xFF\xD8\xFF\xE1\x00\x40abc"];
        yield 'EOI without a scan' => ["\xFF\xD8\xFF\xD9"];
        yield 'scan without an end' => ["\xFF\xD8" . $sos];
        yield 'scan ending in FF' => ["\xFF\xD8" . $sos . "\xFF"];
        yield 'reserved JPG0 marker' => ["\xFF\xD8" . self::seg(0xF0, 'x') . $sos . "\xFF\xD9"];
    }

    #[DataProvider('malformedJpegs')]
    public function testMalformedJpegsAreNotStripped(string $bytes): void
    {
        self::assertNull(ImageMetadataStripper::strip('image/jpeg', $bytes));
    }

    public function testPngMetadataChunksAndTrailersAreDropped(): void
    {
        $clean = ImageFactory::png(32, 16);
        $kept  = self::chunk('gAMA', pack('N', 45455)) . self::chunk('pHYs', pack('NNC', 2835, 2835, 1));
        $junk  = self::chunk('tEXt', "Comment\0<script>alert(1)</script>") . self::chunk('zTXt', "k\0\0x") . self::chunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0GPS")
            . self::chunk('eXIf', 'MM' . "\0*GPSLatitude") . self::chunk('tIME', pack('nCCCCC', 2026, 11, 9, 10, 0, 0))
            . self::chunk('iCCP', "sRGB\0\0profile") . self::chunk('prVt', 'private') . self::chunk('acTL', pack('NN', 2, 0));
        $dirty = substr($clean, 0, 33) . $kept . $junk . substr($clean, 33) . '<html><script>alert(1)</script>';

        self::assertSame(substr($clean, 0, 33) . $kept . substr($clean, 33), ImageMetadataStripper::strip('image/png', $dirty));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedPngs(): iterable
    {
        $png  = ImageFactory::png(8, 8);
        $head = substr($png, 0, 33);
        $tail = substr($png, 33);
        yield 'no signature' => ['PNG'];
        yield 'no IEND' => [substr($png, 0, -12)];
        yield 'bad CRC' => [$head . substr(self::chunk('tEXt', 'a'), 0, -1) . 'X' . $tail];
        yield 'unknown critical chunk' => [$head . self::chunk('ABCD', 'x') . $tail];
        yield 'non-letter chunk type' => [$head . self::chunk('a1cd', 'x') . $tail];
        yield 'first chunk not IHDR' => ["\x89PNG\r\n\x1a\n" . self::chunk('gAMA', 'abcd') . substr($png, 8)];
        yield 'second IHDR' => [$head . substr($png, 8, 25) . $tail];
        yield 'oversized length' => [$head . pack('N', 0x80000000) . 'tEXt' . str_repeat("\0", 8) . $tail];
        yield 'chunk longer than the file' => [$head . pack('N', 999) . 'tEXtabcd'];
    }

    #[DataProvider('malformedPngs')]
    public function testMalformedPngsAreNotStripped(string $bytes): void
    {
        self::assertNull(ImageMetadataStripper::strip('image/png', $bytes));
    }

    public function testWebpMetadataChunksFlagsAndTrailersAreDropped(): void
    {
        // GD writes VP8X (18 bytes) + ALPH + VP8 for a canvas with alpha.
        $vp8  = substr(ImageFactory::webp(16, 16), 30);
        $alph = self::riffChunk('ALPH', 'abc');
        // VP8X flags: ICC 0x20, alpha 0x10, EXIF 0x08, XMP 0x04.
        $vp8x  = static fn (int $flags): string => 'VP8X' . pack('V', 10) . chr($flags) . "\0\0\0\x0F\0\0\x0F\0\0";
        $body  = $vp8x(0x3C) . self::riffChunk('ICCP', 'icc') . $alph . $vp8 . self::riffChunk('EXIF', 'GPS') . self::riffChunk('XMP ', '<x/>') . self::riffChunk('JUNK', 'j');
        $dirty = 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body . '<script>alert(1)</script>';

        $expected = $vp8x(0x10) . $alph . $vp8;
        self::assertSame('RIFF' . pack('V', 4 + strlen($expected)) . 'WEBP' . $expected, ImageMetadataStripper::strip('image/webp', $dirty));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedWebps(): iterable
    {
        $vp8  = substr(ImageFactory::webp(16, 16), 30);
        $riff = static fn (string $body): string => 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
        yield 'not RIFF' => ['RIFX' . pack('V', 4) . 'WEBP'];
        yield 'RIFF size beyond the file' => ['RIFF' . pack('V', 999) . 'WEBP' . $vp8];
        yield 'RIFF size below 4' => ['RIFF' . pack('V', 2) . 'WEBP' . $vp8];
        yield 'truncated chunk header' => [$riff($vp8 . 'EXI')];
        yield 'chunk longer than the RIFF' => [$riff($vp8 . 'EXIF' . pack('V', 50) . 'ab')];
        yield 'animation' => [$riff('ANIM' . pack('V', 6) . str_repeat("\0", 6) . $vp8)];
        yield 'VP8X of a wrong size' => [$riff('VP8X' . pack('V', 4) . "\0\0\0\0" . $vp8)];
        yield 'VP8X not first' => [$riff($vp8 . 'VP8X' . pack('V', 10) . str_repeat("\0", 10))];
        yield 'no image chunk' => [$riff(self::riffChunk('EXIF', 'x'))];
        yield 'two image chunks' => [$riff($vp8 . $vp8)];
    }

    #[DataProvider('malformedWebps')]
    public function testMalformedWebpsAreNotStripped(string $bytes): void
    {
        self::assertNull(ImageMetadataStripper::strip('image/webp', $bytes));
    }

    private static function seg(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private static function riffChunk(string $fourcc, string $data): string
    {
        return $fourcc . pack('V', strlen($data)) . $data . (strlen($data) % 2 === 1 ? "\0" : '');
    }
}
