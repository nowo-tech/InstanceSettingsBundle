<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function substr;

/**
 * Builds the immutable file route URLs: `{files.path_prefix}/{section}/{field}/{hash12}[-{variant}].{ext}`.
 */
final readonly class SettingFileUrls
{
    /** Hex characters of the hash in a URL. */
    public const HASH_LENGTH = 12;

    /** URL extension per stored type. */
    public const EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    /**
     * @param UrlGeneratorInterface|null $urlGenerator router (null: no URLs)
     */
    public function __construct(
        private ?UrlGeneratorInterface $urlGenerator = null,
    ) {
    }

    /**
     * Path of the original (`$variant` 0, in its own type) or of a PNG variant; null when the route is not mounted.
     */
    public function path(string $section, string $field, string $sha256, string $mimeType, int $variant = 0): ?string
    {
        if (!$this->urlGenerator instanceof UrlGeneratorInterface) {
            return null;
        }
        $name = substr($sha256, 0, self::HASH_LENGTH) . ($variant === 0 ? '' : '-' . $variant);
        try {
            return $this->urlGenerator->generate(InstanceSettingsRoutes::FILE, [
                'section' => $section,
                'field'   => $field,
                'file'    => $name,
                'ext'     => $variant === 0 ? self::EXTENSIONS[$mimeType] ?? 'png' : 'png',
            ]);
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
