<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use GdImage;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;

use function abs;
use function getimagesizefromstring;
use function hash;
use function imagealphablending;
use function imagecopyresampled;
use function imagecreatefromstring;
use function imagecreatetruecolor;
use function imageflip;
use function imagejpeg;
use function imagepng;
use function imagerotate;
use function imagesavealpha;
use function imagesx;
use function imagesy;
use function imagewebp;
use function in_array;
use function ini_get;
use function is_string;
use function max;
use function memory_get_usage;
use function min;
use function ob_get_clean;
use function ob_start;
use function ord;
use function round;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unpack;

use const IMAGETYPE_JPEG;
use const IMAGETYPE_PNG;
use const IMAGETYPE_WEBP;
use const IMG_FLIP_HORIZONTAL;
use const IMG_FLIP_VERTICAL;

/**
 * Checks and re-encodes an image upload with GD (spec 220 §5.2–§5.4).
 *
 * 1. The type comes only from the content: the magic bytes (PNG, JPEG, WebP) **and** `getimagesizefromstring()` must
 *    agree; the client name and MIME type are never looked at. SVG, GIF, ICO, BMP, TIFF, AVIF, PDF… are refused.
 * 2. Animated WebP is refused; an APNG keeps its default frame only (GD decodes one frame).
 * 3. Dimensions come from the header, before decoding: a hard pixel cap ({@see ImageSpec::MAX_PIXELS}), the field's
 *    limits (after the JPEG EXIF orientation), the square rule, and a memory budget against the remaining
 *    `memory_limit` (4 B/px × 2 decoded copies, + 6 B/px for any JPEG: libjpeg's progressive coefficient buffer).
 * 4. The image is decoded and re-encoded (PNG → PNG with alpha, JPEG → JPEG q90 turned upright, WebP → WebP q90),
 *    which drops EXIF / GPS / XMP / ICC / text chunks and anything appended (polyglots). Variants are PNG, made from
 *    the re-encoded image, largest first, never upscaled; at most two decoded images are alive at once.
 *
 * Stateless (worker-safe): nothing is kept between calls.
 */
final readonly class ImageProcessor
{
    /** GD truecolor: 4 bytes per pixel. */
    public const BYTES_PER_PIXEL = 4;

    /** Decoded images alive at the peak. */
    public const DECODED_COPIES = 2;

    /** Extra decoder memory budgeted per pixel for every JPEG. */
    public const JPEG_EXTRA_BYTES_PER_PIXEL = 6;

    /** JPEG and WebP re-encode quality. */
    public const QUALITY = 90;

    /** PNG compression level. */
    public const PNG_COMPRESSION = 6;

    /** Content types by GD image type constant. */
    private const IMAGE_TYPES = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];

    /**
     * @param string|null $memoryLimit `memory_limit` to plan the decoding against (null = the current ini value)
     */
    public function __construct(
        private ?string $memoryLimit = null,
    ) {
    }

    /**
     * Type from the magic bytes only (PNG, JPEG, WebP), or null.
     */
    public static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n")                       => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF")                            => 'image/jpeg',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default                                                            => null,
        };
    }

    /**
     * Whether a WebP declares an animation: the VP8X animation flag, or an `ANIM` / `ANMF` chunk anywhere in the RIFF
     * chunk list (walked by chunk sizes, so image data that happens to contain those letters does not count).
     */
    public static function isAnimatedWebp(string $bytes): bool
    {
        $offset = 12;
        $length = strlen($bytes);
        while ($offset + 8 <= $length) {
            $chunk = substr($bytes, $offset, 4);
            if ($chunk === 'ANIM' || $chunk === 'ANMF' || ($chunk === 'VP8X' && $offset + 8 < $length && (ord($bytes[$offset + 8]) & 0x02) !== 0)) {
                return true;
            }
            $size = self::uint('V', $bytes, $offset + 4);
            $offset += 8 + $size + ($size % 2);
        }

        return false;
    }

    /**
     * Checks and re-encodes `$bytes` for a field.
     *
     * @throws ImageRefusedException
     */
    public function process(ImageSpec $spec, string $bytes): ProcessedImage
    {
        if ($bytes === '') {
            throw new ImageRefusedException(ImageRefusedException::EMPTY);
        }
        if (strlen($bytes) > $spec->maxBytes) {
            throw new ImageRefusedException(ImageRefusedException::TOO_LARGE);
        }
        $mime = self::sniff($bytes);
        $info = $mime === null ? false : @getimagesizefromstring($bytes);
        if ($info === false || (self::IMAGE_TYPES[$info[2]] ?? null) !== $mime) {
            throw new ImageRefusedException(ImageRefusedException::INVALID_IMAGE);
        }
        if (!in_array($mime, $spec->types, true)) {
            throw new ImageRefusedException(ImageRefusedException::TYPE_NOT_ALLOWED);
        }
        if ($mime === 'image/webp' && self::isAnimatedWebp($bytes)) {
            throw new ImageRefusedException(ImageRefusedException::ANIMATED);
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width < 1 || $height < 1) {
            throw new ImageRefusedException(ImageRefusedException::INVALID_IMAGE);
        }
        if ($width * $height > ImageSpec::MAX_PIXELS) {
            throw new ImageRefusedException(ImageRefusedException::TOO_MANY_PIXELS);
        }
        $orientation = $mime === 'image/jpeg' ? self::jpegOrientation($bytes) : 1;
        $this->assertDimensions($spec, ...($orientation >= 5 ? [$height, $width] : [$width, $height]));
        $extra = $mime === 'image/jpeg' ? self::JPEG_EXTRA_BYTES_PER_PIXEL : 0;
        if (!self::fitsInMemory($width * $height, $this->memoryLimit ?? ini_get('memory_limit'), memory_get_usage(true), $extra)) {
            throw new ImageRefusedException(ImageRefusedException::TOO_MANY_PIXELS);
        }

        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof GdImage) {
            throw new ImageRefusedException(ImageRefusedException::INVALID_IMAGE);
        }
        if ($orientation !== 1) {
            $image = $this->upright($image, $orientation);
        }
        $original = $this->encode($image, $mime);
        $variants = [];
        foreach ($spec->variants as $size) {
            $image           = $this->fit($image, min($size, max($original->width, $original->height)));
            $variants[$size] = $this->encode($image, 'image/png');
        }
        unset($image);

        return new ProcessedImage($original, $variants, hash('sha256', $bytes));
    }

    /**
     * Whether `$pixels` decoded {@see self::DECODED_COPIES} times plus `$extraPerPixel` decoder bytes fit in what
     * `$memoryLimit` (an ini value: `256M`, `-1`…) leaves above `$usage` bytes. No limit (`-1`, `0`, empty) always fits.
     */
    public static function fitsInMemory(int $pixels, string $memoryLimit, int $usage, int $extraPerPixel = 0): bool
    {
        $value = trim($memoryLimit);
        $limit = (int) $value;
        $limit *= match (strtolower(substr($value, -1))) {
            'g'     => 1024 ** 3,
            'm'     => 1024 ** 2,
            'k'     => 1024,
            default => 1,
        };

        return $limit <= 0 || $usage + $pixels * (self::BYTES_PER_PIXEL * self::DECODED_COPIES + $extraPerPixel) <= $limit;
    }

    /**
     * EXIF orientation (1–8) of a JPEG, read from its APP1 segment without ext-exif; 1 when absent or malformed.
     */
    public static function jpegOrientation(string $bytes): int
    {
        $offset = 2;
        $length = strlen($bytes);
        while ($offset + 4 <= $length && $bytes[$offset] === "\xFF") {
            $marker = ord($bytes[$offset + 1]);
            $size   = self::uint('n', $bytes, $offset + 2);
            if ($marker === 0xE1 && substr($bytes, $offset + 4, 6) === "Exif\0\0") {
                return self::tiffOrientation(substr($bytes, $offset + 10, max(0, $size - 8)));
            }
            if ($marker === 0xDA || $size < 2) {
                break;
            }
            $offset += 2 + $size;
        }

        return 1;
    }

    private function assertDimensions(ImageSpec $spec, int $width, int $height): void
    {
        if ($width > $spec->maxWidth || $height > $spec->maxHeight) {
            throw new ImageRefusedException(ImageRefusedException::DIMENSIONS_TOO_LARGE);
        }
        if ($width < $spec->minWidth || $height < $spec->minHeight) {
            throw new ImageRefusedException(ImageRefusedException::DIMENSIONS_TOO_SMALL);
        }
        if ($spec->square && abs($width - $height) > max($width, $height) * ImageSpec::SQUARE_TOLERANCE) {
            throw new ImageRefusedException(ImageRefusedException::NOT_SQUARE);
        }
    }

    private static function tiffOrientation(string $tiff): int
    {
        $little  = str_starts_with($tiff, 'II');
        $short   = $little ? 'v' : 'n';
        $ifd     = self::uint($little ? 'V' : 'N', $tiff, 4);
        $entries = self::uint($short, $tiff, $ifd);
        for ($i = 0; $i < $entries; ++$i) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > strlen($tiff)) {
                break;
            }
            if (self::uint($short, $tiff, $entry) === 0x0112) {
                $value = self::uint($short, $tiff, $entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /**
     * Unsigned 16-bit (`n` / `v`) or 32-bit (`N` / `V`) number at `$offset`; 0 when out of range.
     */
    private static function uint(string $format, string $bytes, int $offset): int
    {
        $width  = in_array($format, ['n', 'v'], true) ? 2 : 4;
        $values = $offset >= 0 && $offset + $width <= strlen($bytes) ? unpack($format, $bytes, $offset) : false;

        return $values === false ? 0 : (int) $values[1];
    }

    private function upright(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        // imagerotate() turns counter-clockwise: 90 = a quarter left, 270 = a quarter right.
        $angle = match ($orientation) {
            3       => 180,
            6, 7    => 270,
            5, 8    => 90,
            default => 0,
        };
        $rotated = $angle === 0 ? false : imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    private function fit(GdImage $image, int $longest): GdImage
    {
        $width   = imagesx($image);
        $height  = imagesy($image);
        $scale   = $longest / max($width, $height);
        $resized = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);

        return $resized;
    }

    private function encode(GdImage $image, string $mime): EncodedImage
    {
        imagesavealpha($image, true);
        ob_start();
        $ok = match ($mime) {
            'image/png'  => imagepng($image, null, self::PNG_COMPRESSION),
            'image/webp' => imagewebp($image, null, self::QUALITY),
            default      => imagejpeg($image, null, self::QUALITY),
        };
        $bytes = ob_get_clean();
        if (!$ok || !is_string($bytes) || $bytes === '') {
            throw new ImageRefusedException(ImageRefusedException::INVALID_IMAGE);
        }

        return new EncodedImage($bytes, $mime, imagesx($image), imagesy($image));
    }
}
