<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use LogicException;

use function function_exists;
use function gd_info;
use function implode;
use function sprintf;

/**
 * Fails closed when GD cannot sniff and re-encode the accepted image types: an image field is never served without
 * the re-encode step.
 */
final class GdSupport
{
    /** gd_info() keys that must be true. */
    public const REQUIRED = ['PNG Support', 'JPEG Support', 'WebP Support'];

    /**
     * The missing GD capabilities (empty when everything is there).
     *
     * @param array<string, mixed> $info gd_info() output
     *
     * @return list<string>
     */
    public static function missing(array $info): array
    {
        $missing = [];
        foreach (self::REQUIRED as $key) {
            if (($info[$key] ?? false) !== true) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Throws when GD (or one of its PNG, JPEG, WebP codecs) is missing.
     *
     * @param array<string, mixed>|null $info gd_info() output (null = the running PHP)
     */
    public static function assertAvailable(?array $info = null): void
    {
        $missing = self::missing($info ?? (function_exists('gd_info') ? gd_info() : []));
        if ($missing !== []) {
            throw new LogicException(sprintf('NowoInstanceSettingsBundle image fields need ext-gd with PNG, JPEG and WebP support (missing: %s).', implode(', ', $missing)));
        }
    }
}
