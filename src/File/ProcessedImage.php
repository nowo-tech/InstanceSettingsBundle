<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

/**
 * Result of {@see ImageProcessor::process()}: the re-encoded original, its PNG variants and the hash of the bytes as
 * received (import integrity check).
 */
final readonly class ProcessedImage
{
    /**
     * @param EncodedImage $original re-encoded upload (its hash is the stored reference)
     * @param array<int, EncodedImage> $variants longest side => PNG variant, largest first
     * @param string $sourceSha256 SHA-256 of the bytes before re-encoding
     */
    public function __construct(
        public EncodedImage $original,
        public array $variants,
        public string $sourceSha256,
    ) {
    }

    /**
     * SHA-256 of the re-encoded original (the `isf:` reference).
     */
    public function sha256(): string
    {
        return $this->original->sha256();
    }
}
