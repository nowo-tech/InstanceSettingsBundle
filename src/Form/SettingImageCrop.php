<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Form;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\UiKitBundle\Form\ImageCrop;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function abs;
use function array_diff;
use function array_keys;
use function array_map;
use function count;
use function explode;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function max;
use function min;
use function round;
use function sprintf;
use function strval;

/**
 * Opt-in crop step of an image setting field (1.8, owner decision D-20261109-09), on UiKit's image crop (2.43,
 * {@see ImageCrop}): the browser crops a picked image before it is uploaded. The server is unchanged: the upload is
 * still detected, checked against the field's {@see ImageSpec} and re-encoded, and stays the source of truth.
 *
 * Options are UiKit's (`shape`, `aspect`, `max`, `min`, `format`, `max_bytes`, `quality`, `bake_circle`, `preview`,
 * `labels`), over defaults derived from the field's own rules ({@see self::derive()}), and checked against them so a
 * crop the server would refuse cannot be configured: the format must be one the field accepts, `max_bytes` at most its
 * `maxBytes`, a square field keeps the aspect 1, `max` / `min` within its dimensions.
 *
 * Turned on per field with {@see SettingField::withCrop()} (off with {@see SettingField::withoutCrop()}) or for every
 * image field with the bundle option `files.crop` (only `shape`, `quality` and `labels` there).
 */
final class SettingImageCrop
{
    /** Option of {@see SettingImageType}: null (bundle default), false (off) or the field's crop options. */
    public const OPTION = 'crop';

    /** Largest derived `max` (longest output edge, px): a field allowing 4096 px is cropped to at most 2048. */
    public const DERIVED_MAX_CAP = 2048;

    /** Smallest `max` UiKit accepts. */
    public const MIN_MAX = 16;

    /** Derived `max_bytes`, in percent of the field's `maxBytes` (room for a limit counted differently). */
    public const MAX_BYTES_PERCENT = 95;

    /** Keys the bundle default (`files.crop`) may set: they fit every field. */
    public const BUNDLE_KEYS = ['shape', 'quality', 'labels'];

    /**
     * Defaults derived from the field's rules: `min` is the field's larger minimum dimension, clamped to its smaller
     * maximum dimension (the crop is never enlarged, so zooming stops there); `max` the smaller maximum dimension (so
     * a free crop fits both, at most {@see self::DERIVED_MAX_CAP}, at least `min`); a square field gets `aspect` 1, any
     * other `free` while `max` is at least 3 × `min` (a free frame may be 1:3, so its short side keeps `min`), else 1;
     * `format` `auto` when the field takes PNG, WebP and JPEG, else one it takes; `max_bytes`
     * {@see self::MAX_BYTES_PERCENT} % of its `maxBytes`.
     *
     * Never throws. The result may still not {@see self::resolve()} (a field smaller than {@see self::MIN_MAX} px, or
     * whose minimum width does not fit its maximum height): such a field is shown without the crop under the bundle
     * default, and `SettingField::withCrop()` refuses it when the section definitions load.
     *
     * @return array{aspect: int|string, min: int, max: int, format: string, max_bytes: int}
     */
    public static function derive(ImageSpec $spec): array
    {
        $smaller = min($spec->maxWidth, $spec->maxHeight);
        $min     = min(max($spec->minWidth, $spec->minHeight), $smaller);
        $max     = max($min, min($smaller, self::DERIVED_MAX_CAP));

        return [
            'aspect'    => $spec->square || $max < 3 * $min ? 1 : 'free',
            'min'       => $min,
            'max'       => $max,
            'format'    => self::format($spec->types),
            'max_bytes' => max(1, intdiv($spec->maxBytes * self::MAX_BYTES_PERCENT, 100)),
        ];
    }

    /**
     * The crop options of a field: derived defaults, then `$options`, validated by UiKit and against the field.
     *
     * The output of a crop at `max` must fit the field: with a fixed aspect, its width and height within the field's
     * minimum and maximum dimensions and its short side at least `min`; with `free` (any proportions from 1:3 to
     * 3:1), `max` at most the smaller maximum dimension and at least 3 × `min` and the minimum dimensions. A picked
     * image smaller than that gives a smaller crop (never enlarged), which the server refuses as before.
     *
     * @param array<mixed> $options
     *
     * @throws InvalidArgumentException an unknown option, a bad value, or one the field would refuse
     *
     * @return array{shape: string, aspect: string, max: int, min: int, format: string, max_bytes: ?int, quality: float, bake_circle: bool, preview: ?string, labels: array<string, string>}
     */
    public static function resolve(ImageSpec $spec, array $options): array
    {
        $config = ImageCrop::normalize($options + self::derive($spec));
        if (!self::accepts($spec->types, $config['format'])) {
            throw new InvalidArgumentException(sprintf('The crop format "%s" gives files the field does not accept (%s); "auto" needs PNG, WebP and JPEG.', $config['format'], implode(', ', $spec->types)));
        }
        if ($config['max_bytes'] !== null && $config['max_bytes'] > $spec->maxBytes) {
            throw new InvalidArgumentException(sprintf('The crop max_bytes (%d) is over the field maxBytes (%d).', $config['max_bytes'], $spec->maxBytes));
        }
        $ratio = self::ratio($config['aspect']);
        if ($spec->square && ($ratio === null || abs($ratio - 1) > 1e-9)) {
            throw new InvalidArgumentException('A square image field crops with aspect 1 (or the circle shape).');
        }
        $max = $config['max'];
        $min = $config['min'];
        if ($ratio === null) {
            $least = 3 * max($min, $spec->minWidth, $spec->minHeight);
            if ($max > min($spec->maxWidth, $spec->maxHeight) || $max < $least) {
                throw new InvalidArgumentException(sprintf('With aspect "free" the crop max (%d) must be at most the smaller field maximum dimension (%d) and at least %d (3 × the crop min or the field minimum dimensions: a free crop may be 1:3); set a fixed aspect for other sizes.', $max, min($spec->maxWidth, $spec->maxHeight), $least));
            }

            return $config;
        }
        [$width, $height] = $ratio >= 1 ? [$max, (int) round($max / $ratio)] : [(int) round($max * $ratio), $max];
        if ($width > $spec->maxWidth || $height > $spec->maxHeight || $width < $spec->minWidth || $height < $spec->minHeight || min($width, $height) < $min) {
            throw new InvalidArgumentException(sprintf('A crop of aspect %s at max %d (%d × %d) does not fit the field (%d…%d × %d…%d) or its short side is under the crop min (%d).', $config['aspect'], $max, $width, $height, $spec->minWidth, $spec->maxWidth, $spec->minHeight, $spec->maxHeight, $min));
        }

        return $config;
    }

    /**
     * Crop options of a field when the bundle default applies (1.8, audit JP-M1): the bundle default over the
     * field's own options (labels merged per key); null when the field cannot be cropped with them, so a field with
     * valid rules never fails the page. A field whose own options are refused still throws.
     *
     * @param array<mixed>|null $own the field's own options (null = none)
     * @param array<mixed> $bundle the bundle default (`files.crop`)
     *
     * @return array<mixed>|null
     */
    public static function withBundleDefault(ImageSpec $spec, ?array $own, array $bundle): ?array
    {
        if ($own === null) {
            try {
                self::resolve($spec, $bundle);
            } catch (InvalidArgumentException) {
                return null;
            }

            return $bundle;
        }
        $merged = $own + $bundle;
        if (is_array($own['labels'] ?? null) && is_array($bundle['labels'] ?? null)) {
            $merged['labels'] = $own['labels'] + $bundle['labels'];
        }
        try {
            self::resolve($spec, $merged);
        } catch (InvalidArgumentException) {
            return $own;
        }

        return $merged;
    }

    /**
     * Attributes of the field's file input (UiKit's contract, labels translated).
     *
     * @param array<mixed> $options
     *
     * @return array<string, string>
     */
    public static function attributes(ImageSpec $spec, array $options, ?TranslatorInterface $translator = null): array
    {
        return ImageCrop::attributes(self::resolve($spec, $options), $translator);
    }

    /**
     * Checks the bundle default (`files.crop`): only {@see self::BUNDLE_KEYS}, valid for UiKit.
     *
     * @param array<mixed> $options
     *
     * @throws InvalidArgumentException
     *
     * @return array<string, mixed>
     */
    public static function bundleDefault(array $options): array
    {
        $unknown = array_diff(array_map(strval(...), array_keys($options)), self::BUNDLE_KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('files.crop sets only %s for every image field (per field: SettingField::withCrop()); got "%s".', implode(', ', self::BUNDLE_KEYS), implode('", "', $unknown)));
        }
        ImageCrop::normalize($options);

        return $options;
    }

    /**
     * Whether a section form has an image field with the crop step (its page then loads UiKit's `image-crop` script).
     *
     * @param FormInterface<mixed> $form
     */
    public static function inForm(FormInterface $form): bool
    {
        foreach ($form as $child) {
            if ($child->getConfig()->getType()->getInnerType() instanceof SettingImageType && $child->has(SettingImageType::FILE)) {
                $attr = $child->get(SettingImageType::FILE)->getConfig()->getOption('attr');
                if (is_array($attr) && isset($attr[ImageCrop::ATTRIBUTE])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Width / height of a normalized aspect (`free` = null).
     */
    private static function ratio(string $aspect): ?float
    {
        if ($aspect === 'free') {
            return null;
        }
        $parts = explode('/', $aspect);

        return count($parts) === 2 ? (float) $parts[0] / (float) $parts[1] : (float) $aspect;
    }

    /**
     * Derived format: `auto` (WebP, else PNG or JPEG) needs the three types; otherwise one the field takes.
     *
     * @param list<string> $types
     */
    private static function format(array $types): string
    {
        $png  = in_array('image/png', $types, true);
        $webp = in_array('image/webp', $types, true);
        $jpeg = in_array('image/jpeg', $types, true);

        return match (true) {
            $png && $webp && $jpeg => 'auto',
            $png && $webp          => 'webp',
            $png                   => 'png',
            $jpeg                  => 'jpeg',
            default                => 'webp',
        };
    }

    /**
     * Whether the files of `$format` are all types the field takes (`auto` may give WebP, PNG or JPEG).
     *
     * @param list<string> $types
     */
    private static function accepts(array $types, string $format): bool
    {
        $needed = match ($format) {
            'auto'  => ImageSpec::TYPES,
            'png'   => ['image/png'],
            'jpeg'  => ['image/jpeg'],
            default => ['image/webp'],
        };

        return array_diff($needed, $types) === [];
    }
}
