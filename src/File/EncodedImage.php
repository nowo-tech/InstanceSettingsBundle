<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use function hash;
use function strlen;

/**
 * Bytes of one re-encoded image (the original or a variant) with its sniffed type and dimensions.
 */
final readonly class EncodedImage
{
    /**
     * @param string $bytes encoded file
     * @param string $mimeType `image/png`, `image/jpeg` or `image/webp`
     */
    public function __construct(
        public string $bytes,
        public string $mimeType,
        public int $width,
        public int $height,
    ) {
    }

    /**
     * Lower-case hex SHA-256 of the bytes.
     */
    public function sha256(): string
    {
        return hash('sha256', $this->bytes);
    }

    /**
     * Size in bytes.
     */
    public function byteSize(): int
    {
        return strlen($this->bytes);
    }
}
