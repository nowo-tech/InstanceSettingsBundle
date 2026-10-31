<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Portability;

use JsonException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function array_is_list;
use function fclose;
use function fopen;
use function is_array;
use function is_file;
use function is_readable;
use function json_decode;
use function stream_get_contents;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Reads a JSON object from an uploaded file or a path with a hard size cap: at most `maxBytes + 1` bytes are read, so an
 * oversized file is refused without loading it into memory.
 */
final readonly class JsonUploadReader
{
    /** Maximum JSON nesting depth. */
    private const DEPTH = 64;

    /**
     * @param int $maxBytes maximum accepted size in bytes
     */
    public function __construct(
        private int $maxBytes = 1_048_576,
    ) {
    }

    /**
     * Decodes an uploaded file (admin import form).
     *
     * @throws PortabilityException `missing_file`, `too_large`, `invalid_json`, `not_an_object`
     *
     * @return array<string, mixed>
     */
    public function fromUploadedFile(?UploadedFile $file): array
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new PortabilityException('missing_file');
        }

        return $this->fromPath($file->getPathname());
    }

    /**
     * Decodes a file on disk (CLI import).
     *
     * @throws PortabilityException `missing_file`, `too_large`, `invalid_json`, `not_an_object`
     *
     * @return array<string, mixed>
     */
    public function fromPath(string $path): array
    {
        $handle = is_file($path) && is_readable($path) ? fopen($path, 'r') : false;
        if ($handle === false) {
            throw new PortabilityException('missing_file');
        }

        try {
            $raw = stream_get_contents($handle, $this->maxBytes + 1);
        } finally {
            fclose($handle);
        }

        return $this->fromString($raw === false ? '' : $raw);
    }

    /**
     * Decodes a JSON string (same limits).
     *
     * @throws PortabilityException `too_large`, `invalid_json`, `not_an_object`
     *
     * @return array<string, mixed>
     */
    public function fromString(string $raw): array
    {
        if (strlen($raw) > $this->maxBytes) {
            throw new PortabilityException('too_large');
        }

        try {
            $decoded = json_decode($raw, true, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PortabilityException('invalid_json', $e);
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new PortabilityException('not_an_object');
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Configured size cap in bytes.
     */
    public function maxBytes(): int
    {
        return $this->maxBytes;
    }
}
