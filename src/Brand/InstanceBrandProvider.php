<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Brand;

use Exception;
use InvalidArgumentException;
use Nowo\Brand\Contract\Brand;
use Nowo\Brand\Contract\BrandAsset;
use Nowo\Brand\Contract\BrandAssetOrigin;
use Nowo\Brand\Contract\BrandAssetSlot;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\InstanceSettingsBundle\File\StoredSettingFile;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\UiKitBundle\Appearance\Appearance;
use Nowo\UiKitBundle\Appearance\AppearanceLogos;
use Nowo\UiKitBundle\Appearance\AppearancePreset;
use Nowo\UiKitBundle\Appearance\AppearanceProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_keys;
use function is_string;
use function preg_match;
use function trim;

/**
 * The brand bridge (spec 220 §2.2, `nowo_instance_settings.brand.enabled: true`): implements the
 * `nowo-tech/brand-contracts` {@see BrandProviderInterface} from the `brand` and `appearance` sections, falling back
 * to `brand.defaults` (texts and committed default assets) and to UiKit's appearance defaults (colours), and UiKit's
 * {@see AppearanceProviderInterface}, which also carries the stored corner style, border strength and fixed footer.
 *
 * Reads go through {@see InstanceSettingsInterface} (one query per request for every row, memoized per main request),
 * so the bridge adds no query and keeps nothing between requests (stateless, worker-safe).
 *
 * Failures: a stored text the contract refuses (a row written outside the form) falls back to its default; a store
 * failure (database down: an {@see Exception}) is logged and the configured defaults are returned, so an error page
 * or a manifest never fails because of the brand. Programming errors ({@see \Error}) are not swallowed.
 */
final readonly class InstanceBrandProvider implements BrandProviderInterface, AppearanceProviderInterface
{
    /** Image fields read for the fixed slots (field name = slot). */
    private const UPLOAD_SLOTS = BrandSection::FIXED_SLOTS;

    private TranslatorInterface $translator;

    /**
     * @param InstanceSettingsInterface $settings settings store
     * @param BrandDefaults $defaults configured texts and default assets
     * @param BrandPalettes $palettes UiKit presets and appearance defaults
     * @param TranslatorInterface|null $translator translates a default eyebrow given as a key (UiKit appearance)
     * @param LoggerInterface|null $logger receives store failures (names only)
     */
    public function __construct(
        private InstanceSettingsInterface $settings,
        private BrandDefaults $defaults,
        private BrandPalettes $palettes,
        ?TranslatorInterface $translator = null,
        private ?LoggerInterface $logger = null,
    ) {
        $this->translator = $translator ?? new IdentityTranslator();
    }

    /**
     * The brand: stored values over the configured defaults; `isDefault` while nothing is stored in either section.
     */
    public function getBrand(): Brand
    {
        try {
            return $this->build($this->settings->getSection(BrandSection::NAME), $this->settings->getSection(AppearanceSection::NAME), $this->uploads(), $this->hasStoredRows());
        } catch (Exception $e) {
            $this->logger?->warning('Instance settings brand: the settings store failed, using the brand defaults.', ['bundle' => 'NowoInstanceSettingsBundle', 'exception' => $e]);

            return $this->build([], [], [], false);
        }
    }

    /**
     * The UiKit appearance: the brand's name, eyebrow (in the current locale), colours and logos, plus the stored
     * preset ids, corner style, border strength and fixed footer. With an empty store and the same name and eyebrow
     * it equals UiKit's `ConfigAppearanceProvider` output (spec 220 §3.4.3).
     */
    public function getAppearance(): Appearance
    {
        try {
            $values = $this->settings->getSection(AppearanceSection::NAME);
        } catch (Exception $e) {
            $this->logger?->warning('Instance settings brand: the settings store failed, using the appearance defaults.', ['bundle' => 'NowoInstanceSettingsBundle', 'exception' => $e]);
            $values = [];
        }
        $brand      = $this->getBrand();
        $appearance = $this->palettes->appearance($values, $brand->name, $this->plainText($brand->eyebrowText($this->translator)));
        $url        = static fn (string $slot): ?string => $brand->asset($slot)?->url;
        $logos      = new AppearanceLogos(
            $url(BrandAssetSlot::LOGO_LIGHT),
            $url(BrandAssetSlot::LOGO_DARK),
            $url(BrandAssetSlot::MARK),
            $url(BrandAssetSlot::FAVICON),
            $url(BrandAssetSlot::APP_ICON),
            $brand->wordmark,
        );

        // No image at all: no logos object, so every kit template renders as with the config provider.
        return $logos->isEmpty() ? $appearance : $appearance->with(['logos' => $logos]);
    }

    /**
     * @param array<string, mixed> $identity brand section values
     * @param array<string, mixed> $look appearance section values
     * @param array<string, StoredSettingFile> $uploads slot => stored image
     */
    private function build(array $identity, array $look, array $uploads, bool $stored): Brand
    {
        $defaults   = $this->defaults;
        $appearance = $this->palettes->appearance($look, '', '');
        $light      = BrandPalettes::colors($appearance->light, AppearancePreset::MODE_LIGHT);
        $dark       = BrandPalettes::colors($appearance->dark, AppearancePreset::MODE_DARK);
        $assets     = $this->assets($uploads);

        try {
            return new Brand(
                $this->storedText($identity['name'] ?? null) ?? $defaults->name,
                $light,
                $dark,
                $assets,
                $this->storedText($identity['short_name'] ?? null) ?? $defaults->shortName,
                $this->storedText($identity['eyebrow'] ?? null) ?? $defaults->eyebrow(),
                $this->storedText($identity['wordmark'] ?? null) ?? $defaults->wordmark,
                !$stored,
            );
        } catch (InvalidArgumentException) {
            // A stored text the contract refuses (written outside the form): the configured texts apply.
            return new Brand($defaults->name, $light, $dark, $assets, $defaults->shortName, $defaults->eyebrow(), $defaults->wordmark, !$stored);
        }
    }

    /**
     * Resolved assets: the stored upload of a slot, else its config default.
     *
     * @param array<string, StoredSettingFile> $uploads
     *
     * @return array<string, BrandAsset>
     */
    private function assets(array $uploads): array
    {
        $assets = [];
        foreach ([...self::UPLOAD_SLOTS, ...$this->extraSlots()] as $slot) {
            $upload = $uploads[$slot] ?? null;
            $url    = $upload?->url();
            if ($upload instanceof StoredSettingFile && $url !== null && BrandAsset::isSafeUrl($url)) {
                $assets[$slot] = new BrandAsset($slot, $url, $upload->mimeType, $upload->width, $upload->height, $upload->sha256, BrandAssetOrigin::Uploaded);

                continue;
            }
            $default = $this->defaults->asset($slot);
            if ($default instanceof DefaultAsset) {
                $assets[$slot] = $default->toBrandAsset();
            }
        }

        return $assets;
    }

    /**
     * Stored images, slot => file.
     *
     * @return array<string, StoredSettingFile>
     */
    private function uploads(): array
    {
        $files = [];
        foreach ([...self::UPLOAD_SLOTS, ...$this->extraSlots()] as $slot) {
            $file = $this->settings->getFile(BrandSection::NAME . '.' . BrandSection::fieldOf($slot));
            if ($file instanceof StoredSettingFile) {
                $files[$slot] = $file;
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function extraSlots(): array
    {
        $slots = [];
        foreach (array_keys($this->defaults->extraImages) as $id) {
            $slots[] = BrandAssetSlot::extra((string) $id);
        }

        return $slots;
    }

    /**
     * Whether any field of the two sections has a stored row.
     */
    private function hasStoredRows(): bool
    {
        foreach ([BrandSection::NAME, AppearanceSection::NAME] as $section) {
            foreach (array_keys($this->settings->getSection($section)) as $field) {
                if ($this->settings->isOverridden($section . '.' . $field)) {
                    return true;
                }
            }
        }
        foreach ([...self::UPLOAD_SLOTS, ...$this->extraSlots()] as $slot) {
            if ($this->settings->isOverridden(BrandSection::NAME . '.' . BrandSection::fieldOf($slot))) {
                return true;
            }
        }

        return false;
    }

    private function storedText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * A translated eyebrow as plain text for UiKit (audit IS-I4): '' when the catalogue text carries characters the
     * contract refuses (controls, bidi controls, line separators).
     */
    private function plainText(?string $text): string
    {
        return $text === null || preg_match(BrandSection::UNSAFE_TEXT_PATTERN, $text) !== 0 ? '' : $text;
    }
}
