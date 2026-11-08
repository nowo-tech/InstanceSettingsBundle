<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use InvalidArgumentException;

use function array_diff;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_int;
use function rsort;
use function sprintf;

/**
 * Upload rules of an image field ({@see SettingField::image()}): accepted types, byte and dimension limits, square
 * shape, generated variants and whether the file is served anonymously.
 *
 * Immutable; validated when built, so an impossible rule fails when the section definitions load.
 */
final readonly class ImageSpec
{
    /** Types an image field may accept (raster only; SVG, GIF, ICO, BMP, TIFF, AVIF / HEIC and PDF are refused). */
    public const TYPES = ['image/png', 'image/webp', 'image/jpeg'];

    /** Default `maxBytes` (1 MiB). */
    public const DEFAULT_MAX_BYTES = 1_048_576;

    /** Hard ceiling of `maxBytes` (5 MiB). */
    public const MAX_BYTES_CEILING = 5_242_880;

    /** Default `maxWidth` / `maxHeight`. */
    public const DEFAULT_MAX_DIMENSION = 4096;

    /**
     * Hard pixel cap (width × height), checked from the header before any decoding: the default maximum dimensions
     * (4096 × 4096 = 16 777 216 px), so an image at the defaults is accepted. The memory budget still applies.
     */
    public const MAX_PIXELS = self::DEFAULT_MAX_DIMENSION * self::DEFAULT_MAX_DIMENSION;

    /** Largest variant (longest side, in pixels). */
    public const MAX_VARIANT = 4096;

    /** Most variants per field. */
    public const MAX_VARIANTS = 8;

    /** Tolerance of the `square` check (2 %). */
    public const SQUARE_TOLERANCE = 0.02;

    /** @var list<string> */
    public array $types;

    /** @var list<int> longest side of each generated variant, largest first */
    public array $variants;

    /**
     * @param list<string> $types accepted types, a subset of {@see self::TYPES}
     * @param int $maxBytes largest accepted upload (1 … {@see self::MAX_BYTES_CEILING})
     * @param list<int> $variants longest side of each generated PNG variant (1 … {@see self::MAX_VARIANT}), at most {@see self::MAX_VARIANTS}
     * @param bool $public served to anonymous visitors (brand assets); otherwise only to users of the section's area
     */
    public function __construct(
        array $types = self::TYPES,
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
        public int $maxWidth = self::DEFAULT_MAX_DIMENSION,
        public int $maxHeight = self::DEFAULT_MAX_DIMENSION,
        public int $minWidth = 1,
        public int $minHeight = 1,
        public bool $square = false,
        array $variants = [],
        public bool $public = false,
    ) {
        $types = array_values(array_unique($types));
        if ($types === [] || array_diff($types, self::TYPES) !== []) {
            throw new InvalidArgumentException(sprintf('Image field types must be a non-empty subset of %s.', implode(', ', self::TYPES)));
        }
        if ($maxBytes < 1 || $maxBytes > self::MAX_BYTES_CEILING) {
            throw new InvalidArgumentException(sprintf('Image field maxBytes must be between 1 and %d.', self::MAX_BYTES_CEILING));
        }
        if ($minWidth < 1 || $minHeight < 1 || $maxWidth < $minWidth || $maxHeight < $minHeight) {
            throw new InvalidArgumentException('Image field dimensions must be positive with min <= max.');
        }
        if ($minWidth * $minHeight > self::MAX_PIXELS) {
            throw new InvalidArgumentException(sprintf('Image field minimum dimensions exceed the %d pixel cap.', self::MAX_PIXELS));
        }
        $list = [];
        foreach ($variants as $variant) {
            if (!is_int($variant) || $variant < 1 || $variant > self::MAX_VARIANT) {
                throw new InvalidArgumentException(sprintf('Image field variants must be integers between 1 and %d.', self::MAX_VARIANT));
            }
            if (!in_array($variant, $list, true)) {
                $list[] = $variant;
            }
        }
        if (count($list) > self::MAX_VARIANTS) {
            throw new InvalidArgumentException(sprintf('An image field declares at most %d variants.', self::MAX_VARIANTS));
        }
        rsort($list);
        $this->types    = $types;
        $this->variants = $list;
    }

    /**
     * Whether `$variant` is one of the generated variants.
     */
    public function hasVariant(int $variant): bool
    {
        return in_array($variant, $this->variants, true);
    }
}
