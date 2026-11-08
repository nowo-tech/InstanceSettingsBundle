<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Brand;

use Nowo\Brand\Contract\BrandAsset;
use Nowo\Brand\Contract\BrandAssetOrigin;
use Nowo\Brand\Contract\BrandAssetReaderInterface;
use Nowo\Brand\Contract\BrandAssetReadException;
use Nowo\InstanceSettingsBundle\File\SettingFileRecord;
use Nowo\InstanceSettingsBundle\File\SettingFileStoreInterface;

use function file_get_contents;
use function hash;
use function hash_equals;
use function is_string;
use function sprintf;
use function substr;

/**
 * Reads the bytes of a brand asset for consumers that embed it (CID e-mail attachments, PDF images; spec 220 §2.3,
 * §7.5): an upload from the database store (the original or a generated PNG variant), a config default from its
 * committed file.
 *
 * Only assets of the brand section are readable: an uploaded asset is looked up by the field of its slot and its
 * hash, so a forged asset object cannot read another field's image; a config default must match the configured file
 * of its slot and its SHA-256. Messages name the slot and a hash prefix only, never a path or content. Stateless.
 */
final readonly class InstanceBrandAssetReader implements BrandAssetReaderInterface
{
    /**
     * @param BrandDefaults $defaults configured default assets
     * @param SettingFileStoreInterface|null $store image store (null: uploads unreadable)
     */
    public function __construct(
        private BrandDefaults $defaults,
        private ?SettingFileStoreInterface $store = null,
    ) {
    }

    /**
     * The bytes of `$asset` (or of its PNG variant of `$variant` pixels; uploads only).
     */
    public function read(BrandAsset $asset, ?int $variant = null): string
    {
        if ($asset->origin === BrandAssetOrigin::ConfigDefault) {
            return $this->readDefault($asset, $variant);
        }

        $store  = $this->store;
        $record = $store?->find(BrandSection::NAME, BrandSection::fieldOf($asset->slot), $asset->sha256, $variant ?? 0);
        if (!$store instanceof SettingFileStoreInterface || !$record instanceof SettingFileRecord || $record->sha256 !== $asset->sha256) {
            throw $this->unavailable($asset, $variant);
        }

        return $store->read($record);
    }

    private function readDefault(BrandAsset $asset, ?int $variant): string
    {
        $default = $this->defaults->asset($asset->slot);
        if ($variant !== null || !$default instanceof DefaultAsset || $default->sha256 !== $asset->sha256) {
            throw $this->unavailable($asset, $variant);
        }
        $bytes = @file_get_contents($default->path);
        if (!is_string($bytes) || !hash_equals($default->sha256, hash('sha256', $bytes))) {
            // The committed file changed since the container was compiled (clear the cache) or is gone.
            throw $this->unavailable($asset, $variant);
        }

        return $bytes;
    }

    private function unavailable(BrandAsset $asset, ?int $variant): BrandAssetReadException
    {
        return new BrandAssetReadException(sprintf('Brand asset "%s" (%s)%s is not available.', $asset->slot, substr($asset->sha256, 0, 12), $variant === null ? '' : ' variant ' . $variant));
    }
}
