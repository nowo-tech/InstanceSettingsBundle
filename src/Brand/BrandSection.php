<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Brand;

use Nowo\Brand\Contract\BrandAssetSlot;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SectionFallbackProviderInterface;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;

use function array_key_exists;
use function is_string;
use function strlen;
use function substr;

/**
 * The `brand` settings section of the brand bridge (spec 220 §6, `brand.enabled: true`): identity texts and the
 * brand images. Every image field is public (served anonymously by the file route), PNG / JPEG / WebP only (no SVG
 * upload, owner decision Q-O4) and re-encoded by the 1.4 pipeline; the admin page previews the stored image.
 */
final class BrandSection extends AbstractSettingsSection implements SectionFallbackProviderInterface
{
    /** Section name. */
    public const NAME = 'brand';

    /**
     * The fixed image slots ({@see BrandAssetSlot::FIXED}), each stored in the field of the same name. Literal values:
     * the configuration tree reads this constant on every host, also without nowo-tech/brand-contracts installed.
     */
    public const FIXED_SLOTS = ['logo_light', 'logo_dark', 'mark', 'app_icon', 'favicon'];

    /** Field name prefix of an extra image (`extra_<id>` stores slot `extra.<id>`). */
    public const EXTRA_FIELD_PREFIX = 'extra_';

    /** Extra image ids (the field name `extra_<id>` stays within 64 characters). */
    public const EXTRA_ID_PATTERN = '/^[a-z][a-z0-9_]{0,57}$/';

    /** Longest name. */
    public const NAME_MAX = 80;

    /** Longest short name (the contract and the PWA `short_name`). */
    public const SHORT_NAME_MAX = 12;

    /** Longest eyebrow. */
    public const EYEBROW_MAX = 120;

    /** Longest wordmark. */
    public const WORDMARK_MAX = 40;

    /** Characters the brand contract refuses in texts: controls, bidi embeddings / overrides / isolates, line separators. */
    public const UNSAFE_TEXT_PATTERN = '/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}\p{Zl}\p{Zp}]/u';

    /** Variants generated for the app icon (PWA 512 / 192, apple-touch 180). */
    public const APP_ICON_VARIANTS = [512, 192, 180];

    /** Variants generated for the favicon. */
    public const FAVICON_VARIANTS = [32, 16];

    /**
     * @param BrandDefaults $defaults configured defaults and extra images
     * @param BrandFallbackProviderInterface|null $fallbacks host values to seed from (spec 220 §7.1)
     */
    public function __construct(
        private readonly BrandDefaults $defaults,
        private readonly ?BrandFallbackProviderInterface $fallbacks = null,
    ) {
    }

    /**
     * `brand`.
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * `brand.section.label` in the bundle domain.
     */
    public function getLabel(): string
    {
        return 'brand.section.label';
    }

    /**
     * The bundle domain.
     */
    public function getTranslationDomain(): string
    {
        return NowoInstanceSettingsBundle::TRANSLATION_DOMAIN;
    }

    /**
     * `brand.section.intro`.
     */
    public function getIntro(): string
    {
        return 'brand.section.intro';
    }

    /**
     * Name, short name, eyebrow, wordmark, the five fixed images and the configured extra images.
     *
     * @return list<SettingField>
     */
    public function getFields(): iterable
    {
        $fields = [
            $this->text('name', self::NAME_MAX, $this->defaults->name, true),
            $this->text('short_name', self::SHORT_NAME_MAX),
            $this->text('eyebrow', self::EYEBROW_MAX),
            $this->text('wordmark', self::WORDMARK_MAX),
            $this->image(BrandAssetSlot::LOGO_LIGHT, maxWidth: 2400, maxHeight: 800),
            $this->image(BrandAssetSlot::LOGO_DARK, maxWidth: 2400, maxHeight: 800),
            $this->image(BrandAssetSlot::MARK, maxWidth: 1024, maxHeight: 1024, minSide: 64, square: true),
            $this->image(BrandAssetSlot::APP_ICON, minSide: 512, square: true, variants: self::APP_ICON_VARIANTS),
            $this->image(BrandAssetSlot::FAVICON, minSide: 32, square: true, variants: self::FAVICON_VARIANTS),
        ];
        foreach ($this->defaults->extraImages as $id => $rules) {
            $field = SettingField::image(
                self::EXTRA_FIELD_PREFIX . $id,
                $rules['label'] ?? 'brand.field.extra',
                $rules['help'],
                maxBytes: $rules['max_bytes'],
                maxWidth: $rules['max_width'],
                maxHeight: $rules['max_height'],
                square: $rules['square'],
                public: true,
            );
            $fields[] = $field->withFormOptions(['preview' => true])->withGroup('images');
        }

        return $fields;
    }

    /**
     * Slot of a field (`logo_light` → `logo_light`, `extra_mascot` → `extra.mascot`), or null for a text field.
     */
    public static function slotOf(string $field): ?string
    {
        foreach (self::FIXED_SLOTS as $slot) {
            if ($field === $slot) {
                return $slot;
            }
        }
        if (strlen($field) > strlen(self::EXTRA_FIELD_PREFIX) && substr($field, 0, strlen(self::EXTRA_FIELD_PREFIX)) === self::EXTRA_FIELD_PREFIX) {
            return BrandAssetSlot::EXTRA_PREFIX . substr($field, strlen(self::EXTRA_FIELD_PREFIX));
        }

        return null;
    }

    /**
     * Field of a slot (`extra.mascot` → `extra_mascot`).
     */
    public static function fieldOf(string $slot): string
    {
        return BrandAssetSlot::isExtra($slot) ? self::EXTRA_FIELD_PREFIX . substr($slot, strlen(BrandAssetSlot::EXTRA_PREFIX)) : $slot;
    }

    /**
     * The host fallback provider's `brand` values for the text fields (image keys and unknown keys are skipped).
     *
     * @return iterable<SettingFallback>
     */
    public function getFallbacks(): iterable
    {
        if (!$this->fallbacks instanceof BrandFallbackProviderInterface) {
            return [];
        }
        $text = ['name' => true, 'short_name' => true, 'eyebrow' => true, 'wordmark' => true];
        $list = [];
        foreach ($this->fallbacks->getFallbacks(self::NAME) as $field => $value) {
            if (is_string($field) && array_key_exists($field, $text)) {
                $list[] = SettingFallback::plain($field, $value);
            }
        }

        return $list;
    }

    /**
     * @param positive-int $max
     */
    private function text(string $name, int $max, ?string $default = null, bool $required = false): SettingField
    {
        return (new SettingField(
            $name,
            SettingType::String,
            $default,
            'brand.field.' . $name,
            'brand.help.' . $name,
            $required,
            constraints: [new Length(max: $max), new Regex(pattern: self::UNSAFE_TEXT_PATTERN, match: false)],
        ))->withGroup('identity');
    }

    /**
     * @param list<int> $variants
     */
    private function image(string $slot, int $maxWidth = 4096, int $maxHeight = 4096, int $minSide = 1, bool $square = false, array $variants = []): SettingField
    {
        return SettingField::image(
            $slot,
            'brand.field.' . $slot,
            'brand.help.' . $slot,
            maxWidth: $maxWidth,
            maxHeight: $maxHeight,
            minWidth: $minSide,
            minHeight: $minSide,
            square: $square,
            variants: $variants,
            public: true,
        )->withFormOptions(['preview' => true])->withGroup('images');
    }
}
