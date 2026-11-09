<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use function array_pop;
use function array_unshift;
use function explode;
use function getcwd;
use function implode;
use function realpath;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function trim;

/**
 * Where a path really points, also before it exists (1.7, audit JG-L6): the path is normalised lexically (`//`, `.`,
 * `..`), its nearest existing ancestor is resolved with `realpath()` (symlinks followed) and the missing rest is
 * appended. Used to refuse a filesystem store directory under the public web root, at container build and again before
 * the store creates it. Stateless.
 *
 * 1.7.1: also the "local directory path" rule of `files.directory` and `seed.base_dir`, applied by the container to a
 * literal value and at run time to an `%env(...)%` value (whose real value the container cannot see).
 */
final class PathGuard
{
    /**
     * The resolved form of `$path`, with `/` separators and a trailing `/`.
     */
    public static function resolve(string $path): string
    {
        $path     = str_replace('\\', '/', $path);
        $absolute = str_starts_with($path, '/');
        $parts    = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        $missing = [];
        while (($real = realpath(($absolute ? '/' : '') . implode('/', $parts))) === false && $parts !== []) {
            array_unshift($missing, array_pop($parts));
        }
        $base = rtrim(str_replace('\\', '/', $real === false ? (string) getcwd() : $real), '/');

        return $base . '/' . ($missing === [] ? '' : implode('/', $missing) . '/');
    }

    /**
     * Whether `$path` is `$root` or inside it, once both are resolved.
     */
    public static function isUnder(string $path, string $root): bool
    {
        return str_starts_with(self::resolve($path), self::resolve($root));
    }

    /**
     * Whether `$path` can be a local directory path: not blank, no NUL byte, no URL or stream wrapper (`://`). A blank
     * value matters most: `realpath('')` is the current directory.
     */
    public static function isLocalPath(string $path): bool
    {
        return trim($path) !== '' && !str_contains($path, "\0") && !str_contains($path, '://');
    }
}
