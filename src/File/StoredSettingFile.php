<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;

/**
 * The image stored in an image setting field ({@see InstanceSettingsInterface::getFile()}).
 *
 * URLs are immutable: they carry the first 12 hex characters of the hash, so a new upload gets a new URL.
 */
final readonly class StoredSettingFile
{
    /**
     * @param string $sha256 SHA-256 of the re-encoded original (the stored `isf:` reference)
     * @param string $mimeType sniffed type of the original
     * @param array<int, string|null> $urls 0 (original) and each variant size => URL path of the file route (null: not mounted)
     */
    public function __construct(
        public string $section,
        public string $field,
        public string $sha256,
        public string $mimeType,
        public int $width,
        public int $height,
        public int $byteSize,
        public bool $public,
        private array $urls,
    ) {
    }

    /**
     * URL of the original (`$variant` null) or of a generated variant; null for a variant the field does not declare
     * or when the file route is not mounted.
     */
    public function url(?int $variant = null): ?string
    {
        return $this->urls[$variant ?? 0] ?? null;
    }

    /**
     * Sizes of the generated PNG variants, largest first.
     *
     * @return list<int>
     */
    public function variants(): array
    {
        $sizes = [];
        foreach ($this->urls as $size => $url) {
            if ($size !== 0) {
                $sizes[] = $size;
            }
        }

        return $sizes;
    }
}
