<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Brand;

/**
 * `nowo_instance_settings.brand.defaults` and `extra_images`, resolved when the container compiles: the texts used
 * while nothing is stored and the config default assets per slot (spec 220 §4.2, §6).
 */
final readonly class BrandDefaults
{
    /** @var array<string, DefaultAsset> slot => asset */
    public array $assets;

    /**
     * @param string $name brand name while none is stored
     * @param string|null $shortName short name (null = the contract default: the name cut at 12)
     * @param string|null $eyebrowText literal eyebrow
     * @param string|null $eyebrowKey translation key of the eyebrow (wins over nothing; never both)
     * @param string $eyebrowDomain translation domain of `$eyebrowKey`
     * @param string|null $wordmark wordmark (null = the lower-cased name)
     * @param array<string, array{slot: string, url: string, mime_type: string, width: int|null, height: int|null, sha256: string, path: string}> $assets slot => {@see DefaultAsset::toArray()}
     * @param array<string, array{label: string|null, help: string|null, max_width: int, max_height: int, max_bytes: int, square: bool}> $extraImages id => upload rules of `extra.<id>`
     */
    public function __construct(
        public string $name,
        public ?string $shortName = null,
        public ?string $eyebrowText = null,
        public ?string $eyebrowKey = null,
        public string $eyebrowDomain = 'messages',
        public ?string $wordmark = null,
        array $assets = [],
        public array $extraImages = [],
    ) {
        $list = [];
        foreach ($assets as $slot => $asset) {
            $list[$slot] = DefaultAsset::fromArray($asset);
        }
        $this->assets = $list;
    }

    /**
     * The default eyebrow: the literal, a {@see TranslatableBrandText} for a key, or null.
     */
    public function eyebrow(): string|TranslatableBrandText|null
    {
        if ($this->eyebrowKey !== null) {
            return new TranslatableBrandText($this->eyebrowKey, $this->eyebrowDomain);
        }

        return $this->eyebrowText;
    }

    /**
     * The default asset of a slot, or null.
     */
    public function asset(string $slot): ?DefaultAsset
    {
        return $this->assets[$slot] ?? null;
    }
}
