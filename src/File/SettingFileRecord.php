<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use DateTimeImmutable;

/**
 * Metadata of one stored image row (original or variant), without its bytes.
 */
final readonly class SettingFileRecord
{
    /**
     * @param int $variant 0 for the original, else the longest side of the PNG variant
     */
    public function __construct(
        public int $id,
        public string $section,
        public string $field,
        public string $sha256,
        public int $variant,
        public string $mimeType,
        public int $width,
        public int $height,
        public int $byteSize,
        public ?DateTimeImmutable $orphanedAt = null,
    ) {
    }
}
