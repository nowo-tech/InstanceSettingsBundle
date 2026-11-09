<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use RuntimeException;

use function array_diff;
use function array_values;
use function chmod;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function getimagesize;
use function getimagesizefromstring;
use function hash;
use function hash_file;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function mkdir;
use function preg_match;
use function realpath;
use function rename;
use function rsort;
use function scandir;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function tempnam;
use function umask;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Filesystem store of the image setting files (1.7, owner decision D-20261109-03; opt-in with
 * `files.store: filesystem`): the image of a field lives in a **stable file per field** inside the configured
 * directory, so an upload in development leaves a file ready to commit next to the seed file and git diffs stay
 * readable, and production mounts the directory as a persistent volume. No database blob is written.
 *
 * Layout (every name is derived from the section / field allowlist, never from a request or a client file name):
 *
 * - `<directory>/<section>/<field>.<png|jpg|webp>`: the original (extension from the sniffed type);
 * - `<directory>/<section>/.variants/<field>-<hash12>-<size>.png`: the generated PNG variants, bound to the hash of the
 *   original they were made from (a stale variant is never served for a newer original).
 *
 * Semantics that differ from {@see DoctrineSettingFileStore}:
 *
 * - **The file on disk is authoritative.** The setting row only says that the field is set; {@see self::findImage()}
 *   answers the field's current file whatever hash the row holds (a `git pull` that changed a committed logo is served
 *   at once, under a new hash-bearing URL, so the immutable HTTP cache never serves a stale logo).
 *   {@see self::find()} (the file route) still requires the URL hash prefix to match the current file.
 * - **No orphan retention:** replacing an image overwrites the stable file (and removes the file of another extension
 *   and the old variants); clearing a field deletes its file at once ({@see self::orphan()}). {@see self::purge()} only
 *   removes stale variants and leftover temporary files, never an original (a committed file of a field that is not
 *   seeded yet must survive).
 *
 * Writes are confined to the directory: the base is resolved with `realpath()`, the section and variants directories
 * must be real directories (never symlinks), a target that is a symlink is refused, and each file is written to a
 * temporary file in the same directory, `chmod`ed to 0644 (minus the umask) and `rename()`d into place, so a reader or
 * a concurrent save never sees a half-written file. Directories are created 0755 (minus the umask). The directory does
 * not need to be under the public web root: the files are served by the file route only.
 *
 * Stateless (worker-safe): nothing is kept between calls; the hash of the current file is computed per lookup.
 */
final readonly class FilesystemSettingFileStore implements SettingFileStoreInterface
{
    /** Sub-directory of the variants inside a section directory. */
    public const VARIANTS_DIR = '.variants';

    /** Prefix of the temporary files (same directory as the target, renamed into place). */
    public const TEMP_PREFIX = '.tmp-';

    /** Section and field names accepted in a path (the registry pattern, checked again here). */
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** Variant file name: `<field>-<hash12>-<size>.png`. */
    private const VARIANT_PATTERN = '/^([a-z][a-z0-9_]{0,63})-([0-9a-f]{12})-([1-9][0-9]{0,3})\.png$/';

    /**
     * @param string $directory base directory of the files (`files.directory`); created on the first write
     */
    public function __construct(
        private string $directory,
    ) {
    }

    /**
     * Path the original of a field is stored at for a type, whether or not it exists; null for a name outside the
     * allowlist or an unknown type. The seeder compares it with a seed image path to adopt a committed file in place.
     */
    public function originalPath(string $section, string $field, string $mimeType): ?string
    {
        $extension = SettingFileUrls::EXTENSIONS[$mimeType] ?? null;
        if ($extension === null || !$this->validNames($section, $field)) {
            return null;
        }

        return $this->root() . DIRECTORY_SEPARATOR . $section . DIRECTORY_SEPARATOR . $field . '.' . $extension;
    }

    /**
     * Metadata of the field's current file (or of its variant) when its hash starts with `$hashPrefix`.
     */
    public function find(string $section, string $field, string $hashPrefix, int $variant): ?SettingFileRecord
    {
        $original = preg_match('/^[0-9a-f]{12,64}$/', $hashPrefix) === 1 ? $this->current($section, $field) : null;
        if (!$original instanceof SettingFileRecord || !str_starts_with($original->sha256, $hashPrefix)) {
            return null;
        }

        return $variant === 0 ? $original : $this->variantRecord($original, $variant);
    }

    /**
     * Metadata of the field's current file and of its variants, keyed by variant. The requested hash is not compared:
     * the file on disk is authoritative (see the class description).
     */
    public function findImage(string $section, string $field, string $sha256): array
    {
        $original = $this->current($section, $field);
        if (!$original instanceof SettingFileRecord) {
            return [];
        }
        $records = [0 => $original];
        foreach ($this->variantSizes($section, $field, $original->sha256) as $size) {
            $record = $this->variantRecord($original, $size);
            if ($record instanceof SettingFileRecord) {
                $records[$size] = $record;
            }
        }

        return $records;
    }

    /**
     * Bytes of a record ('' when the file changed or disappeared meanwhile).
     */
    public function read(SettingFileRecord $record): string
    {
        $path  = $record->variant === 0 ? $this->existingOriginal($record->section, $record->field)[0] ?? null : $this->variantPath($record->section, $record->field, $record->sha256, $record->variant);
        $bytes = $path === null ? null : $this->readCapped($path);

        return $bytes === null || ($record->variant === 0 && hash('sha256', $bytes) !== $record->sha256) ? '' : $bytes;
    }

    /**
     * Writes the original to the field's stable file and its variants, then removes the field's file of another
     * extension and the variants of another hash.
     *
     * @throws RuntimeException when a directory cannot be created or written, or a target is a symlink
     */
    public function save(string $section, string $field, ProcessedImage $image, DateTimeImmutable $at): void
    {
        $this->store($section, $field, $image->original->bytes, $image->original->mimeType, $image);
    }

    /**
     * Adopts a file that already sits at the field's stable path (a committed file the seed points at): keeps its
     * bytes as they are, writes the variants made from `$image` (its processed form) and returns the SHA-256 of the
     * file. `$bytes` must be the content of that file, already checked by {@see ImageProcessor::process()}.
     *
     * @throws RuntimeException when a directory cannot be written, or a target is a symlink
     */
    public function adopt(string $section, string $field, string $bytes, ProcessedImage $image): string
    {
        $this->store($section, $field, $bytes, ImageProcessor::sniff($bytes) ?? $image->original->mimeType, $image);

        return hash('sha256', $bytes);
    }

    /**
     * Deletes the field's file (and its variants) when it still holds the image `$sha256`: clearing a field removes
     * its file at once, a newer image saved meanwhile is kept.
     */
    public function orphan(string $section, string $field, string $sha256, DateTimeImmutable $at): void
    {
        if ($this->current($section, $field)?->sha256 !== $sha256) {
            return;
        }
        @unlink($this->existingOriginal($section, $field)[0] ?? '');
        $this->removeVariants($section, $field, null);
    }

    /**
     * No orphan marks on the filesystem (a cleared image is deleted at once): always 0.
     */
    public function markUnreferenced(array $referenced, DateTimeImmutable $at, bool $dryRun = false): int
    {
        return 0;
    }

    /**
     * Deletes the stale variants (of another hash than the field's current file, or of a field without a file) and
     * leftover temporary files; never an original. Returns the number of files concerned.
     */
    public function purge(DateTimeImmutable $orphanedBefore, callable $referenced, bool $dryRun = false): int
    {
        $root  = $this->existingRoot();
        $stale = [];
        foreach ($root === null ? [] : $this->entries($root) as $section) {
            $directory = $root . DIRECTORY_SEPARATOR . $section;
            $variants  = $directory . DIRECTORY_SEPARATOR . self::VARIANTS_DIR;
            if (preg_match(self::NAME_PATTERN, $section) !== 1 || !$this->isRealDirectory($directory)) {
                continue;
            }
            foreach ([$directory, $variants] as $level) {
                foreach ($this->isRealDirectory($level) ? $this->entries($level) : [] as $name) {
                    if (str_starts_with($name, self::TEMP_PREFIX) || ($level === $variants && $this->isStaleVariant($section, $name))) {
                        $stale[] = $level . DIRECTORY_SEPARATOR . $name;
                    }
                }
            }
        }
        foreach ($dryRun ? [] : $stale as $path) {
            @unlink($path);
        }

        return count($stale);
    }

    /**
     * Writes the original bytes (unless the stable file already holds them) and the variants, then removes the
     * other-extension original and the variants of another hash.
     */
    private function store(string $section, string $field, string $bytes, string $mimeType, ProcessedImage $image): void
    {
        $extension = SettingFileUrls::EXTENSIONS[$mimeType] ?? null;
        if ($extension === null || !$this->validNames($section, $field)) {
            throw new RuntimeException(sprintf('Instance settings file store: invalid section / field name or type for "%s.%s".', $section, $field));
        }
        $directory = $this->directory($section);
        $target    = $directory . DIRECTORY_SEPARATOR . $field . '.' . $extension;
        $sha       = hash('sha256', $bytes);
        $this->write($directory, $target, $bytes, is_file($target) && !is_link($target) && hash_file('sha256', $target) === $sha);
        foreach (SettingFileUrls::EXTENSIONS as $other) {
            $other = $directory . DIRECTORY_SEPARATOR . $field . '.' . $other;
            if ($other !== $target && (is_file($other) || is_link($other))) {
                @unlink($other);
            }
        }
        $variants = $image->variants === [] ? null : $this->directory($section, self::VARIANTS_DIR);
        foreach ($image->variants as $size => $encoded) {
            $this->write((string) $variants, $variants . DIRECTORY_SEPARATOR . sprintf('%s-%s-%d.png', $field, substr($sha, 0, SettingFileUrls::HASH_LENGTH), $size), $encoded->bytes);
        }
        $this->removeVariants($section, $field, $sha);
    }

    /**
     * Atomic write: a temporary file in the same directory, chmod 0644 minus the umask, then rename over the target.
     */
    private function write(string $directory, string $target, string $bytes, bool $unchanged = false): void
    {
        if (is_link($target)) {
            throw new RuntimeException(sprintf('Instance settings file store: refusing to write through the symlink "%s".', $target));
        }
        if ($unchanged) {
            return;
        }
        $temp = @tempnam($directory, self::TEMP_PREFIX);
        // tempnam() falls back to the system temp directory when $directory is not writable: refuse that too.
        $ok = is_string($temp) && dirname((string) realpath($temp)) === $directory && @file_put_contents($temp, $bytes) === strlen($bytes) && @chmod($temp, 0o644 & ~umask()) && @rename($temp, $target);
        if (!$ok) {
            @unlink((string) $temp);

            throw new RuntimeException(sprintf('Instance settings file store: could not write "%s" (is the directory writable?).', $target));
        }
    }

    /**
     * The real directory of a section (or of its variants), created 0755 when missing; a symlink is refused.
     */
    private function directory(string $section, ?string $sub = null): string
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o755, true)) {
            throw new RuntimeException(sprintf('Instance settings file store: cannot create the directory "%s" (files.directory).', $this->directory));
        }
        $levels = [$this->root() . DIRECTORY_SEPARATOR . $section];
        if ($sub !== null) {
            $levels[] = $levels[0] . DIRECTORY_SEPARATOR . $sub;
        }
        foreach ($levels as $level) {
            if (is_link($level) || (!is_dir($level) && !@mkdir($level, 0o755))) {
                throw new RuntimeException(sprintf('Instance settings file store: "%s" is a symlink or cannot be created; refusing to write.', $level));
            }
        }

        return $levels[count($levels) - 1];
    }

    /**
     * Metadata of the field's current original, or null (no file, a symlink, too large, not a PNG / JPEG / WebP by its
     * content, or a content type that disagrees with the extension).
     */
    private function current(string $section, string $field): ?SettingFileRecord
    {
        [$path, $extension] = $this->existingOriginal($section, $field) ?? [null, null];
        $bytes              = $path === null ? null : $this->readCapped($path);
        $mime               = $bytes === null ? null : ImageProcessor::sniff($bytes);
        $info               = $mime === null || SettingFileUrls::EXTENSIONS[$mime] !== $extension ? false : @getimagesizefromstring((string) $bytes);
        if ($info === false) {
            return null;
        }

        return new SettingFileRecord(0, $section, $field, hash('sha256', (string) $bytes), 0, (string) $mime, $info[0], $info[1], strlen((string) $bytes));
    }

    /**
     * Path and extension of the field's existing original (first of png, jpg, webp), never through a symlink.
     *
     * @return array{0: string, 1: string}|null
     */
    private function existingOriginal(string $section, string $field): ?array
    {
        $root = $this->existingRoot();
        foreach ($root === null || !$this->validNames($section, $field) ? [] : SettingFileUrls::EXTENSIONS as $extension) {
            $path = $root . DIRECTORY_SEPARATOR . $section . DIRECTORY_SEPARATOR . $field . '.' . $extension;
            if (is_file($path) && $this->inside($path, (string) $root)) {
                return [$path, $extension];
            }
        }

        return null;
    }

    private function variantRecord(SettingFileRecord $original, int $size): ?SettingFileRecord
    {
        $path = $this->variantPath($original->section, $original->field, $original->sha256, $size);
        $info = $path === null ? false : @getimagesize($path);
        if ($info === false || $info['mime'] !== 'image/png') {
            return null;
        }

        return new SettingFileRecord(0, $original->section, $original->field, $original->sha256, $size, 'image/png', $info[0], $info[1], (int) filesize((string) $path));
    }

    private function variantPath(string $section, string $field, string $sha256, int $size): ?string
    {
        $root = $this->existingRoot();
        $path = $root . DIRECTORY_SEPARATOR . $section . DIRECTORY_SEPARATOR . self::VARIANTS_DIR . DIRECTORY_SEPARATOR . sprintf('%s-%s-%d.png', $field, substr($sha256, 0, SettingFileUrls::HASH_LENGTH), $size);

        return $root !== null && $this->validNames($section, $field) && is_file($path) && $this->inside($path, $root) ? $path : null;
    }

    /**
     * Variant sizes stored for the field's original `$sha256`, largest first.
     *
     * @return list<int>
     */
    private function variantSizes(string $section, string $field, string $sha256): array
    {
        $sizes = [];
        foreach ($this->variantNames($section) as $name) {
            if (preg_match(self::VARIANT_PATTERN, $name, $match) === 1 && $match[1] === $field && str_starts_with($sha256, $match[2])) {
                $sizes[] = (int) $match[3];
            }
        }
        rsort($sizes);

        return $sizes;
    }

    /**
     * Removes the field's variants, except those of `$keepSha` (null = all).
     */
    private function removeVariants(string $section, string $field, ?string $keepSha): void
    {
        foreach ($this->variantNames($section) as $name) {
            if (preg_match(self::VARIANT_PATTERN, $name, $match) === 1 && $match[1] === $field && ($keepSha === null || !str_starts_with($keepSha, $match[2]))) {
                @unlink($this->root() . DIRECTORY_SEPARATOR . $section . DIRECTORY_SEPARATOR . self::VARIANTS_DIR . DIRECTORY_SEPARATOR . $name);
            }
        }
    }

    /**
     * Whether a file of a variants directory is a variant of another hash than its field's current file (or of a
     * field without a file); names that are not variants are left alone.
     */
    private function isStaleVariant(string $section, string $name): bool
    {
        if (preg_match(self::VARIANT_PATTERN, $name, $match) !== 1) {
            return false;
        }

        return !str_starts_with($this->current($section, $match[1])->sha256 ?? '', $match[2]);
    }

    /**
     * @return list<string>
     */
    private function variantNames(string $section): array
    {
        $directory = $this->root() . DIRECTORY_SEPARATOR . $section . DIRECTORY_SEPARATOR . self::VARIANTS_DIR;

        return $this->validNames($section, 'x') && $this->isRealDirectory($directory) ? $this->entries($directory) : [];
    }

    /**
     * The base directory resolved with `realpath()` (a symlinked base is allowed: it is the operator's mount), or null
     * when it does not exist yet.
     */
    private function existingRoot(): ?string
    {
        $real = realpath($this->directory);

        return $real !== false && is_dir($real) ? $real : null;
    }

    private function root(): string
    {
        return $this->existingRoot() ?? $this->directory;
    }

    /**
     * Whether a path is not a symlink and resolves inside `$root`.
     */
    private function inside(string $path, string $root): bool
    {
        return !is_link($path) && str_starts_with((string) realpath($path), $root . DIRECTORY_SEPARATOR);
    }

    private function isRealDirectory(string $path): bool
    {
        return !is_link($path) && is_dir($path);
    }

    private function validNames(string $section, string $field): bool
    {
        return preg_match(self::NAME_PATTERN, $section) === 1 && preg_match(self::NAME_PATTERN, $field) === 1;
    }

    /**
     * Content of a regular file (not a symlink) of 1 … {@see ImageSpec::MAX_BYTES_CEILING} bytes, else null.
     */
    private function readCapped(string $path): ?string
    {
        // Callers resolve the path without symlinks first; a file removed meanwhile reads as nothing.
        $bytes = is_link($path) ? '' : (string) @file_get_contents($path, false, null, 0, ImageSpec::MAX_BYTES_CEILING + 1);

        return $bytes === '' || strlen($bytes) > ImageSpec::MAX_BYTES_CEILING ? null : $bytes;
    }

    /**
     * @return list<string>
     */
    private function entries(string $directory): array
    {
        return array_values(array_diff(@scandir($directory) ?: [], ['.', '..']));
    }
}
