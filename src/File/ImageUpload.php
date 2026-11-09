<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function fclose;
use function fopen;
use function is_file;
use function is_readable;
use function str_contains;
use function stream_get_contents;
use function stripos;
use function strlen;

/**
 * Raw image input of {@see InstanceSettingsInterface::save()} for an image field:
 * bytes (API, import) or a file on disk (form upload). Read with a hard cap: at most `maxBytes + 1` bytes ever enter
 * memory, so an oversized file is refused without loading it whole.
 */
final readonly class ImageUpload
{
    private function __construct(
        private ?string $bytes,
        private ?string $path,
    ) {
    }

    /**
     * Bytes held in memory.
     */
    public static function fromBytes(string $bytes): self
    {
        return new self($bytes, null);
    }

    /**
     * A local file on disk (the client name and type are ignored). A URL or stream wrapper path (`phar://`, `http://`,
     * `data:` …) is refused when read (1.7): only plain local files are accepted.
     */
    public static function fromPath(string $path): self
    {
        return new self(null, $path);
    }

    /**
     * A form upload; a failed upload is refused when read.
     */
    public static function fromUploadedFile(UploadedFile $file): self
    {
        return new self(null, $file->isValid() ? $file->getPathname() : '');
    }

    /**
     * The bytes, refused when larger than `$maxBytes`.
     *
     * @throws ImageRefusedException `too_large`, `upload_failed`
     */
    public function read(int $maxBytes): string
    {
        if ($this->bytes !== null) {
            $bytes = $this->bytes;
        } else {
            $handle = $this->path !== '' && !$this->isWrapper((string) $this->path) && is_file((string) $this->path) && is_readable((string) $this->path) ? fopen((string) $this->path, 'r') : false;
            if ($handle === false) {
                throw new ImageRefusedException(ImageRefusedException::UPLOAD_FAILED);
            }
            try {
                $bytes = (string) stream_get_contents($handle, $maxBytes + 1);
            } finally {
                fclose($handle);
            }
        }
        if (strlen($bytes) > $maxBytes) {
            throw new ImageRefusedException(ImageRefusedException::TOO_LARGE);
        }

        return $bytes;
    }

    /**
     * Whether a path names a URL or stream wrapper instead of a local file.
     */
    private function isWrapper(string $path): bool
    {
        return str_contains($path, '://') || stripos($path, 'data:') === 0;
    }
}
