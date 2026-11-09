<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Ui;

use Nowo\InstanceSettingsBundle\File\StoredSettingFile;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\UiKitBundle\View\Label;

use function max;
use function min;
use function round;

/**
 * Admin preview of the image stored in an image field (1.5, audit IO Q3): the file route URL of the stored original,
 * printed only as an auto-escaped `<img src>`. Never the bytes, a `data:` URI or base64.
 */
final readonly class ImagePreview
{
    /** Largest preview box, in CSS pixels (the image is scaled down to fit, never up). */
    public const MAX_WIDTH = 160;

    /** Largest preview height, in CSS pixels. */
    public const MAX_HEIGHT = 64;

    /**
     * @param string $field field name
     * @param string $url file route URL of the stored original
     * @param Label $label field label (alt text and caption)
     * @param int $width preview width
     * @param int $height preview height
     */
    public function __construct(
        public string $field,
        public string $url,
        public Label $label,
        public int $width,
        public int $height,
    ) {
    }

    /**
     * Preview of a stored image, scaled to fit {@see self::MAX_WIDTH} × {@see self::MAX_HEIGHT}; null when the file
     * route is not mounted (no URL).
     */
    public static function of(StoredSettingFile $file, SettingField $field, string $domain): ?self
    {
        $url = $file->url();
        if ($url === null) {
            return null;
        }
        $scale = min(1.0, self::MAX_WIDTH / max(1, $file->width), self::MAX_HEIGHT / max(1, $file->height));

        return new self(
            $field->name,
            $url,
            new Label($field->labelKey(), $domain),
            max(1, (int) round($file->width * $scale)),
            max(1, (int) round($file->height * $scale)),
        );
    }
}
