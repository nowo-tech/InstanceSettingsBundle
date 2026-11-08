<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Brand;

use InvalidArgumentException;
use Nowo\Brand\Contract\BrandAsset;
use Nowo\Brand\Contract\BrandAssetOrigin;

use function getimagesize;
use function hash_file;
use function is_array;
use function is_file;
use function is_link;
use function pathinfo;
use function preg_match;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;

/**
 * A config default brand asset (spec 220 §4.2, §7.2): a committed file under `brand.public_dir`, served by the web
 * server as a plain static file and trusted (it may be SVG or ICO, and {@see BrandAsset::isInlineSafe()} is true).
 *
 * Built when the container compiles ({@see self::fromFile()}): the path is checked (relative, no `..`, no symlink out
 * of the public directory, an image extension), and its type, dimensions and SHA-256 are read once.
 */
final readonly class DefaultAsset
{
    /** Relative paths: letters, digits, `.`, `_`, `-` and `/` segments; never `..` nor a leading slash. */
    public const PATH_PATTERN = '#^[A-Za-z0-9_][A-Za-z0-9._-]*(?:/[A-Za-z0-9_][A-Za-z0-9._-]*)*$#';

    /** Media type per accepted extension. */
    public const TYPES = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
    ];

    /**
     * @param string $slot brand slot ({@see BrandSection::FIXED_SLOTS} or `extra.<id>`)
     * @param string $url root-relative URL (`/` + the relative path)
     * @param string $path absolute path of the file (read by {@see InstanceBrandAssetReader})
     */
    public function __construct(
        public string $slot,
        public string $url,
        public string $mimeType,
        public ?int $width,
        public ?int $height,
        public string $sha256,
        public string $path,
    ) {
    }

    /**
     * Reads a configured default asset.
     *
     * @throws InvalidArgumentException when the path is unsafe, not an image file or missing
     */
    public static function fromFile(string $slot, string $relativePath, string $publicDir): self
    {
        if (preg_match(self::PATH_PATTERN, $relativePath) !== 1 || str_contains('/' . $relativePath . '/', '/../') || str_contains('/' . $relativePath . '/', '/./')) {
            throw new InvalidArgumentException(sprintf('Brand default asset "%s": "%s" must be a path relative to brand.public_dir (letters, digits, ".", "_", "-" and "/"; no "..").', $slot, $relativePath));
        }
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $type      = self::TYPES[$extension] ?? null;
        if ($type === null) {
            throw new InvalidArgumentException(sprintf('Brand default asset "%s": "%s" must be a PNG, JPEG, WebP, GIF, SVG or ICO file.', $slot, $relativePath));
        }
        $root = realpath($publicDir);
        $file = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $relativePath);
        if ($root === false || $file === false || !is_file($file) || is_link($root . DIRECTORY_SEPARATOR . $relativePath) || !str_starts_with($file, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException(sprintf('Brand default asset "%s": "%s" is not a file inside brand.public_dir.', $slot, $relativePath));
        }

        $width  = null;
        $height = null;
        if ($type !== 'image/svg+xml') {
            $size = @getimagesize($file);
            if (!is_array($size) || $size[0] < 1 || $size[1] < 1) {
                throw new InvalidArgumentException(sprintf('Brand default asset "%s": "%s" is not a readable image.', $slot, $relativePath));
            }
            [$width, $height] = [$size[0], $size[1]];
        }

        return new self($slot, '/' . $relativePath, $type, $width, $height, (string) hash_file('sha256', $file), $file);
    }

    /**
     * @return array{slot: string, url: string, mime_type: string, width: int|null, height: int|null, sha256: string, path: string}
     */
    public function toArray(): array
    {
        return [
            'slot'      => $this->slot,
            'url'       => $this->url,
            'mime_type' => $this->mimeType,
            'width'     => $this->width,
            'height'    => $this->height,
            'sha256'    => $this->sha256,
            'path'      => $this->path,
        ];
    }

    /**
     * @param array{slot: string, url: string, mime_type: string, width: int|null, height: int|null, sha256: string, path: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['slot'], $data['url'], $data['mime_type'], $data['width'], $data['height'], $data['sha256'], $data['path']);
    }

    /**
     * The contract asset (origin {@see BrandAssetOrigin::ConfigDefault}).
     */
    public function toBrandAsset(): BrandAsset
    {
        return new BrandAsset($this->slot, $this->url, $this->mimeType, $this->width, $this->height, $this->sha256, BrandAssetOrigin::ConfigDefault);
    }
}
