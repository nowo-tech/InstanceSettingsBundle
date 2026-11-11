<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Brand;

use Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore;
use Nowo\InstanceSettingsBundle\File\SettingFileRecord;
use Nowo\InstanceSettingsBundle\File\SettingFileUrls;
use Nowo\UiKitBundle\View\ErrorIllustration;
use Nowo\UiKitBundle\View\ErrorIllustrationProviderInterface;

use function preg_match;

/**
 * UiKit error illustration bridge (1.13): the illustration of an error page from the uploaded brand extra images.
 * Point `nowo_ui_kit.error_pages.illustration_provider` at {@see self::SERVICE_ID}.
 *
 * For status code N it answers the first that exists:
 *
 * 1. the uploaded brand extra image `error_<N>` (field `extra_error_<N>`), when `brand.error_illustrations.per_code`;
 * 2. the uploaded base extra image (`brand.error_illustrations.base`, e.g. `mascot`);
 * 3. null: UiKit uses its own `error_pages.illustrations` map (code, then `default`), then no illustration.
 *
 * Only **uploaded** (stored) images count: a configured `extra_images.<id>.default` is never returned, so the defaults
 * stay with UiKit's map. Needs `files.store: filesystem`: the image is resolved from the field's file on disk
 * (`<files.directory>/brand/extra_<id>.<ext>`) and its metadata file (hash, width, height), never from the database
 * or over HTTP, so it also answers while the database is down. The URL is the file route of the original
 * (`{files.path_prefix}/brand/extra_<id>/<hash12>.<ext>`), without a WebP source.
 *
 * Stateless (FrankenPHP worker mode with the kernel kept alive): every call resolves from disk; nothing is kept.
 */
final readonly class BrandErrorIllustrationProvider implements ErrorIllustrationProviderInterface
{
    /** Public service id to put in `nowo_ui_kit.error_pages.illustration_provider`. */
    public const SERVICE_ID = 'nowo_instance_settings.brand.error_illustration_provider';

    /** Extra image id prefix of a per-code illustration (`error_404`). */
    public const CODE_ID_PREFIX = 'error_';

    /** Extra image ids of a per-code illustration: `error_` and an HTTP error status (400–599). */
    public const CODE_ID_PATTERN = '/^error_([45][0-9]{2})$/D';

    /**
     * @param FilesystemSettingFileStore $store filesystem image store (reads only)
     * @param SettingFileUrls $urls file route URLs
     * @param array<int, string> $codeFields status code => field of its declared extra image (`extra_error_<code>`)
     * @param string|null $baseField field of the base extra image (`extra_<base>`), null = none
     */
    public function __construct(
        private FilesystemSettingFileStore $store,
        private SettingFileUrls $urls,
        private array $codeFields = [],
        private ?string $baseField = null,
    ) {
    }

    /**
     * The uploaded `error_<code>` image, else the uploaded base image, else null (UiKit's map).
     */
    public function getIllustration(int $statusCode): ?ErrorIllustration
    {
        foreach ([$this->codeFields[$statusCode] ?? null, $this->baseField] as $field) {
            $record = $field === null ? null : $this->store->storedRecord(BrandSection::NAME, $field);
            if (!$record instanceof SettingFileRecord) {
                continue;
            }
            $url = $this->urls->path(BrandSection::NAME, $record->field, $record->sha256, $record->mimeType);

            return $url === null ? null : ErrorIllustration::fromUrl($url, $record->width, $record->height);
        }

        return null;
    }

    /**
     * Status code => field of each declared per-code extra image (`error_404` → `[404 => 'extra_error_404']`).
     *
     * @param iterable<int|string> $extraIds declared `brand.extra_images` ids
     *
     * @return array<int, string>
     */
    public static function codeFields(iterable $extraIds): array
    {
        $fields = [];
        foreach ($extraIds as $id) {
            if (preg_match(self::CODE_ID_PATTERN, (string) $id, $match) === 1) {
                $fields[(int) $match[1]] = BrandSection::EXTRA_FIELD_PREFIX . $id;
            }
        }

        return $fields;
    }
}
