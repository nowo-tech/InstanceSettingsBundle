<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use function chr;
use function crc32;
use function in_array;
use function intdiv;
use function ord;
use function pack;
use function preg_match;
use function strlen;
use function strpos;
use function substr;
use function unpack;

/**
 * Removes metadata and trailing data from a PNG, JPEG or WebP **without touching the pixel data** (1.7.1): the kept
 * segments / chunks are copied byte for byte, so the decoded pixels are identical and a file that has nothing to remove
 * comes back unchanged (same bytes, same hash). Used to adopt a committed file at its stable path (seed, filesystem
 * store) without the generational loss of a re-encode, while still serving no EXIF / GPS / XMP / ICC / text / appended
 * payload (audit JG-L3).
 *
 * Allow-lists, not deny-lists — anything not listed is dropped:
 * - **JPEG:** every marker segment needed to decode (SOFn, DHT, DQT, DRI, DAC, SOS and its entropy-coded data, RSTn),
 *   `APP0` "JFIF" without a thumbnail, `APP14` "Adobe" (its colour-transform flag changes the decoding), and the
 *   `COM` that GD itself writes (`CREATOR: gd-jpeg …`, so the bundle's own re-encodes are already clean). Dropped:
 *   every other `APPn` (EXIF, XMP, ICC, MPF, Photoshop…), every other `COM`, and anything after `EOI`.
 * - **PNG:** the critical chunks plus `tRNS`, `gAMA`, `cHRM`, `sRGB`, `sBIT`, `bKGD`, `pHYs` (rendering only), each
 *   only at the length the PNG specification gives it for the colour type (audit JK-L1; another length re-encodes).
 *   Dropped: `tEXt`, `zTXt`, `iTXt`, `eXIf`, `tIME`, `iCCP`, APNG frames (`acTL` / `fcTL` / `fdAT`: the default image is
 *   kept, as the re-encode keeps it), private chunks, and anything after `IEND`.
 * - **WebP:** `VP8X` (its ICC / EXIF / XMP flags cleared), `ALPH`, `VP8 ` / `VP8L`. Dropped: `ICCP`, `EXIF`, `XMP `,
 *   unknown chunks, and anything after the RIFF size (which is rewritten).
 *
 * ICC profiles are dropped like the re-encode drops them (GD ignores them on decode, so the served pixels are the ones
 * GD validated); commit sRGB images. Returns null — the caller then re-encodes — when the structure cannot be parsed
 * safely: a truncated or overlong segment / chunk, a PNG CRC mismatch or unknown critical chunk, a JPEG without `SOS`
 * or `EOI`, a WebP without exactly one image chunk or with animation chunks. Data hidden **inside** the pixel streams
 * (entropy-coded data, IDAT, VP8) is not inspected; only a re-encode would rewrite it.
 *
 * Stateless (worker-safe).
 */
final class ImageMetadataStripper
{
    /** PNG chunks kept besides the critical ones. */
    public const PNG_KEPT_ANCILLARY = ['tRNS', 'gAMA', 'cHRM', 'sRGB', 'sBIT', 'bKGD', 'pHYs'];

    /** PNG critical chunks. */
    private const PNG_CRITICAL = ['IHDR', 'PLTE', 'IDAT', 'IEND'];

    /** JPEG segments needed to decode: SOF0-3, SOF5-7, SOF9-11, SOF13-15, DHT, DAC, DQT, DNL, DRI, DHP, EXP. */
    private const JPEG_DECODING = [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF, 0xC4, 0xCC, 0xDB, 0xDC, 0xDD, 0xDE, 0xDF];

    /** PNG signature. */
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** The comment GD writes in every JPEG it encodes. */
    private const GD_COMMENT = '/^CREATOR: gd-jpeg v\d+\.\d+ \(using IJG JPEG v\d+[a-z]?\), (quality = \d{1,3}|default quality)\n?\z/';

    /** VP8X flags of the dropped WebP chunks: ICC (0x20), EXIF (0x08), XMP (0x04). */
    private const VP8X_METADATA_FLAGS = 0x20 | 0x08 | 0x04;

    /**
     * The bytes without metadata / trailing data (identical to `$bytes` when there was nothing to remove), or null when
     * the structure cannot be parsed safely or the type is not PNG / JPEG / WebP.
     */
    public static function strip(string $mime, string $bytes): ?string
    {
        return match ($mime) {
            'image/jpeg' => self::jpeg($bytes),
            'image/png'  => self::png($bytes),
            'image/webp' => self::webp($bytes),
            default      => null,
        };
    }

    private static function jpeg(string $bytes): ?string
    {
        $length = strlen($bytes);
        if (substr($bytes, 0, 2) !== "\xFF\xD8") {
            return null;
        }
        $out    = "\xFF\xD8";
        $offset = 2;
        $scan   = false;
        while (true) {
            if ($offset + 2 > $length || $bytes[$offset] !== "\xFF") {
                return null;
            }
            // Fill bytes before a marker.
            while ($offset + 2 <= $length && $bytes[$offset + 1] === "\xFF") {
                ++$offset;
            }
            if ($offset + 2 > $length) {
                return null;
            }
            $marker = ord($bytes[$offset + 1]);
            if ($marker === 0xD9) {
                return $scan ? $out . "\xFF\xD9" : null;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= substr($bytes, $offset, 2);
                $offset += 2;

                continue;
            }
            if ($marker === 0x00 || $marker === 0xD8) {
                return null;
            }
            $size = self::uint16($bytes, $offset + 2);
            if ($size < 2 || $offset + 2 + $size > $length) {
                return null;
            }
            $segment = substr($bytes, $offset, 2 + $size);
            $payload = substr($segment, 4);
            $offset += 2 + $size;
            if ($marker === 0xDA) {
                $end = self::entropyEnd($bytes, $offset);
                if ($end === null) {
                    return null;
                }
                $out .= $segment . substr($bytes, $offset, $end - $offset);
                $offset = $end;
                $scan   = true;

                continue;
            }
            $keep = self::keepJpegSegment($marker, $payload);
            if ($keep === null) {
                return null;
            }
            if ($keep) {
                $out .= $segment;
            }
        }
    }

    /**
     * Whether a JPEG segment (other than SOS) is kept; null for a marker that is neither a decoding segment nor
     * metadata (JPG extensions, reserved markers: the structure is not understood).
     */
    private static function keepJpegSegment(int $marker, string $payload): ?bool
    {
        return match (true) {
            in_array($marker, self::JPEG_DECODING, true) => true,
            // APP0 JFIF without a thumbnail (version 2, density 5, thumbnail size 2 = 14 bytes).
            $marker === 0xE0 => strlen($payload) === 14 && substr($payload, 0, 5) === "JFIF\0" && $payload[12] === "\0" && $payload[13] === "\0",
            // APP14 Adobe: fixed 12 bytes, its transform flag changes the colour decoding.
            $marker === 0xEE                 => strlen($payload) === 12 && substr($payload, 0, 5) === 'Adobe',
            $marker > 0xE0 && $marker < 0xF0 => false,
            $marker === 0xFE                 => preg_match(self::GD_COMMENT, $payload) === 1,
            default                          => null,
        };
    }

    /**
     * Offset of the marker that ends an entropy-coded segment starting at `$offset` (stuffed `FF 00`, `RSTn` and fill
     * bytes are part of the data), or null when the file ends first.
     */
    private static function entropyEnd(string $bytes, int $offset): ?int
    {
        $length = strlen($bytes);
        while (($at = strpos($bytes, "\xFF", $offset)) !== false && $at + 1 < $length) {
            $next = ord($bytes[$at + 1]);
            if ($next === 0x00 || $next === 0xFF || ($next >= 0xD0 && $next <= 0xD7)) {
                $offset = $at + ($next === 0xFF ? 1 : 2);

                continue;
            }

            return $at;
        }

        return null;
    }

    private static function png(string $bytes): ?string
    {
        if (substr($bytes, 0, 8) !== self::PNG_SIGNATURE) {
            return null;
        }
        $length  = strlen($bytes);
        $out     = self::PNG_SIGNATURE;
        $offset  = 8;
        $colour  = -1;
        $palette = 0;
        while ($offset + 12 <= $length) {
            $size = self::uint32be($bytes, $offset);
            $type = substr($bytes, $offset + 4, 4);
            if ($size > 0x7FFFFFFF || $offset + 12 + $size > $length || preg_match('/^[A-Za-z]{4}\z/', $type) !== 1
                || ($offset === 8) !== ($type === 'IHDR')) {
                return null;
            }
            $data = substr($bytes, $offset + 8, $size);
            if (self::uint32be($bytes, $offset + 8 + $size) !== crc32($type . $data)) {
                return null;
            }
            $chunk = substr($bytes, $offset, 12 + $size);
            $offset += 12 + $size;
            if ($type === 'IHDR') {
                $colour = $size === 13 ? ord($data[9]) : -1;
            } elseif ($type === 'PLTE') {
                $palette = intdiv($size, 3);
            } elseif (in_array($type, self::PNG_KEPT_ANCILLARY, true) && !self::pngAncillaryLengthOk($type, $size, $colour, $palette)) {
                // A kept chunk of a size the spec does not allow can only carry other data (audit JK-L1).
                return null;
            }
            if (in_array($type, self::PNG_CRITICAL, true) || in_array($type, self::PNG_KEPT_ANCILLARY, true)) {
                $out .= $chunk;
            } elseif ((ord($type[0]) & 0x20) === 0) {
                // An unknown critical chunk: the image cannot be understood without it.
                return null;
            }
            if ($type === 'IEND') {
                return $out;
            }
        }

        return null;
    }

    /**
     * Whether a kept PNG ancillary chunk has the length the PNG specification gives it for the image's colour type
     * (0 grey, 2 RGB, 3 palette, 4 grey + alpha, 6 RGBA; `$palette` = PLTE entries seen so far).
     */
    private static function pngAncillaryLengthOk(string $type, int $size, int $colour, int $palette): bool
    {
        return match ($type) {
            'gAMA'  => $size === 4,
            'cHRM'  => $size === 32,
            'sRGB'  => $size === 1,
            'pHYs'  => $size === 9,
            'sBIT'  => $size === ([0 => 1, 2 => 3, 3 => 3, 4 => 2, 6 => 4][$colour] ?? 0),
            'bKGD'  => $size === ([0 => 2, 4 => 2, 2 => 6, 6 => 6, 3 => 1][$colour] ?? 0),
            default => match ($colour) { // tRNS
                0       => $size === 2,
                2       => $size === 6,
                3       => $size >= 1 && $size <= $palette,
                default => false,
            },
        };
    }

    private static function webp(string $bytes): ?string
    {
        $length = strlen($bytes);
        if (substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            return null;
        }
        $end = 8 + self::uint32le($bytes, 4);
        if ($end > $length || $end < 12) {
            return null;
        }
        $body   = '';
        $images = 0;
        $offset = 12;
        while ($offset < $end) {
            if ($offset + 8 > $end) {
                return null;
            }
            $fourcc = substr($bytes, $offset, 4);
            $size   = self::uint32le($bytes, $offset + 4);
            $padded = $size + ($size % 2);
            if ($offset + 8 + $padded > $end) {
                return null;
            }
            $chunk = substr($bytes, $offset, 8 + $padded);
            $offset += 8 + $padded;
            if ($fourcc === 'ANIM' || $fourcc === 'ANMF') {
                return null;
            }
            if ($fourcc === 'VP8X') {
                if ($size !== 10 || $body !== '') {
                    return null;
                }
                $chunk[8] = chr(ord($chunk[8]) & ~self::VP8X_METADATA_FLAGS & 0xFF);
                $body .= $chunk;
            } elseif ($fourcc === 'VP8 ' || $fourcc === 'VP8L') {
                ++$images;
                $body .= $chunk;
            } elseif ($fourcc === 'ALPH') {
                $body .= $chunk;
            }
        }

        return $images === 1 ? 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body : null;
    }

    private static function uint16(string $bytes, int $offset): int
    {
        return self::uint('n', 2, $bytes, $offset);
    }

    private static function uint32be(string $bytes, int $offset): int
    {
        return self::uint('N', 4, $bytes, $offset);
    }

    private static function uint32le(string $bytes, int $offset): int
    {
        return self::uint('V', 4, $bytes, $offset);
    }

    /**
     * Unsigned number of `$width` bytes at `$offset`; 0 when out of range.
     */
    private static function uint(string $format, int $width, string $bytes, int $offset): int
    {
        $values = $offset + $width <= strlen($bytes) ? unpack($format, $bytes, $offset) : false;

        return $values === false ? 0 : (int) $values[1];
    }
}
