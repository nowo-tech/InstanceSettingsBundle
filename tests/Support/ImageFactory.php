<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use GdImage;
use RuntimeException;

use function chr;
use function crc32;
use function imagealphablending;
use function imagecolorallocatealpha;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagejpeg;
use function imagepng;
use function imagesavealpha;
use function imagewebp;
use function ob_get_clean;
use function ob_start;
use function pack;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Builds real and hostile image bytes for the image field tests.
 */
final class ImageFactory
{
    public static function png(int $width = 64, int $height = 64, int $red = 200): string
    {
        $image = self::canvas($width, $height, $red);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public static function jpeg(int $width = 64, int $height = 64, int $red = 200): string
    {
        $image = self::canvas($width, $height, $red);
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    public static function webp(int $width = 64, int $height = 64, int $red = 200): string
    {
        $image = self::canvas($width, $height, $red);
        ob_start();
        imagewebp($image, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * A JPEG with an APP1 Exif segment (orientation + a fake GPS / comment payload) right after SOI.
     */
    public static function jpegWithExif(int $orientation = 1, int $width = 80, int $height = 40, bool $bigEndian = true): string
    {
        $jpeg  = self::jpeg($width, $height);
        $short = $bigEndian ? 'n' : 'v';
        $long  = $bigEndian ? 'N' : 'V';
        // TIFF header, IFD0 with one entry (0x0112 Orientation, SHORT, 1), next IFD 0, then a marker payload.
        $tiff = ($bigEndian ? 'MM' : 'II') . pack($short, 42) . pack($long, 8)
            . pack($short, 1) . pack($short, 0x0112) . pack($short, 3) . pack($long, 1) . pack($short, $orientation) . pack($short, 0)
            . pack($long, 0) . 'GPSLatitude=48.8584;SECRET-EXIF-PAYLOAD';
        $app1 = "Exif\0\0" . $tiff;

        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    /**
     * A PNG header (signature + IHDR) declaring huge dimensions, without any pixel data (decompression bomb probe).
     */
    public static function pngHeader(int $width, int $height): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', $ihdr) . self::chunk('IDAT', "\x78\x9c\x03\x00\x00\x00\x00\x01") . self::chunk('IEND', '');
    }

    /**
     * An APNG: a PNG with an `acTL` chunk after IHDR (GD decodes the default frame only).
     */
    public static function apng(): string
    {
        $png = self::png(32, 32);

        // Signature (8) + IHDR chunk (4 + 4 + 13 + 4 = 25).
        return substr($png, 0, 33) . self::chunk('acTL', pack('NN', 2, 0)) . substr($png, 33);
    }

    /**
     * An "animated" WebP: a still WebP whose RIFF list gets a VP8X header with the animation flag and an ANIM chunk.
     */
    public static function animatedWebp(bool $flag = true, bool $animChunk = true): string
    {
        $still = self::webp(16, 16);
        $body  = substr($still, 12);
        $vp8x  = 'VP8X' . pack('V', 10) . chr($flag ? 0x02 : 0x00) . "\0\0\0\x0F\0\0\x0F\0\0";
        $anim  = $animChunk ? 'ANIM' . pack('V', 6) . str_repeat("\0", 6) : '';
        $riff  = 'WEBP' . $vp8x . $anim . $body;

        return 'RIFF' . pack('V', strlen($riff)) . $riff;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private static function canvas(int $width, int $height, int $red): GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        if (!$image instanceof GdImage) {
            throw new RuntimeException('GD cannot create an image.');
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $color = imagecolorallocatealpha($image, max(0, min(255, $red)), 40, 90, 0);
        $half  = imagecolorallocatealpha($image, 10, 160, 30, 64);
        if ($color === false || $half === false) {
            throw new RuntimeException('GD cannot allocate a colour.');
        }
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);
        imagefilledrectangle($image, 0, 0, (int) ($width / 2), (int) ($height / 2), $half);

        return $image;
    }
}
