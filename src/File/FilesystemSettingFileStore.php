<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionMiddleware;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use RuntimeException;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_diff;
use function array_key_last;
use function array_pop;
use function array_slice;
use function array_values;
use function chmod;
use function count;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function filesize;
use function getimagesize;
use function getimagesizefromstring;
use function hash;
use function hash_file;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function realpath;
use function rename;
use function rsort;
use function rtrim;
use function scandir;
use function spl_object_id;
use function sprintf;
use function stat;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function substr;
use function tempnam;
use function time;
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
 *   original they were made from (a stale variant is never served for a newer original);
 * - `<directory>/<section>/.variants/<field>.meta`: hash, type and dimensions of the original, trusted while its size,
 *   mtime, ctime and inode match, so a lookup (or a wrong-hash request) does not read and hash the file (audit JG-L1).
 *
 * **Transactional** ({@see StagedSettingFileStoreInterface}, audit JG-M1): inside a unit, a save writes its files as
 * temporary files next to their targets and a clear is only queued; the renames and deletes happen once the outermost
 * unit committed, and a rolled-back unit removes its temporary files. A rename that fails after the commit leaves the
 * previous file in place (still served) and nothing is removed for it.
 *
 * Semantics that differ from {@see DoctrineSettingFileStore}:
 *
 * - **The file on disk is authoritative.** The setting row only says that the field is set; {@see self::findImage()}
 *   answers the field's current file whatever hash the row holds (a `git pull` that changed a committed logo is served
 *   at once, under a new hash-bearing URL). {@see self::find()} still requires the URL hash prefix to match.
 * - **No orphan retention:** a replaced image is overwritten (the file of another extension and the old variants are
 *   removed); a cleared field's file is deleted, whatever its hash. {@see self::purge()} only removes stale variants,
 *   stale metadata and temporary files older than {@see self::TEMP_MAX_AGE}; never an original.
 *
 * Writes are confined to the directory: the base is resolved with `realpath()`, the section and variants directories
 * must be real directories (never symlinks), a target that is a symlink is refused, and each file is written to a
 * temporary file in the same directory (0644 minus the umask) and `rename()`d into place. Directories are created 0755
 * minus the umask. The directory must not be under the public web root (the container refuses a literal one, the
 * constructor one given through `%env(...)%`, 1.7.1). Each variants directory holds a `.gitignore` with `*` (1.7.1).
 *
 * A transaction the host opens around a bundle call is followed through {@see FileStoreTransactionMiddleware}:
 * the changes the bundle committed inside it wait for its real commit, and its rollback discards them.
 *
 * Holds the staged changes of the open units and transactions only; {@see self::reset()} discards them (worker-safe).
 */
final class FilesystemSettingFileStore implements StagedSettingFileStoreInterface, ResetInterface
{
    /** Sub-directory of the variants inside a section directory. */
    public const VARIANTS_DIR = '.variants';

    /** Git ignore file written in each variants directory (1.7.1). */
    public const GITIGNORE = '.gitignore';

    /** Content of {@see self::GITIGNORE}: everything in the directory, the file itself included. */
    public const GITIGNORE_CONTENT = "# Generated by nowo-tech/instance-settings-bundle: variants and metadata are rebuilt from the originals.\n*\n";

    /** Prefix of the temporary files (same directory as the target, renamed into place). */
    public const TEMP_PREFIX = '.tmp-';

    /** Age (seconds) after which {@see self::purge()} removes a leftover temporary file. */
    public const TEMP_MAX_AGE = 3600;

    /** Section and field names accepted in a path (the registry pattern, checked again here). */
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

    /** Variant file name: `<field>-<hash12>-<size>.png`. */
    private const VARIANT_PATTERN = '/^([a-z][a-z0-9_]{0,63})-([0-9a-f]{12})-([1-9][0-9]{0,3})\.png$/D';

    /** Metadata file name: `<field>.meta`. */
    private const META_PATTERN = '/^([a-z][a-z0-9_]{0,63})\.meta$/D';

    /**
     * Staged operations of each open unit: `[write, temp, target]`, `[meta, section, field, target, sha, image]`,
     * `[clean, section, field, kept target|null, kept sha|null]`.
     *
     * @var list<list<array{0: string, 1: string, 2: string, 3: string|null, 4: string|null, 5?: array<string, int|string>}>>
     */
    private array $units = [];

    /**
     * Open database transactions of the bundle's connection (reported by the DBAL middleware), keyed by connection,
     * in the order they began: the real transaction, then one level per savepoint, each with the operations the bundle
     * committed while it was the innermost level. A rolled-back level drops its operations (audit JG-M3).
     *
     * @var array<int, list<array{0: string|null, 1: list<array{0: string, 1: string, 2: string, 3: string|null, 4: string|null, 5?: array<string, int|string>}>}>>
     */
    private array $transactions = [];

    /**
     * The directory is checked here as the container checks a literal one (1.7.1): with `%env(...)%` it is only known
     * now. A blank value, a NUL byte or a `://` wrapper, and a directory under a forbidden root (also when it does not
     * exist yet, see {@see PathGuard}), are refused before any file is read or written.
     *
     * @param string $directory base directory of the files (`files.directory`); created on the first write
     * @param list<string> $forbiddenRoots directories the base must not be in (the public web root, audit JG-L6)
     *
     * @throws RuntimeException when the directory is not a local path or is under a forbidden root
     */
    public function __construct(
        private readonly string $directory,
        private readonly array $forbiddenRoots = [],
    ) {
        if (!PathGuard::isLocalPath($directory)) {
            throw new RuntimeException(sprintf('Instance settings file store: files.directory must be a local directory path, got "%s".', $directory));
        }
        if (!PathGuard::isAbsolute($directory)) {
            throw new RuntimeException(sprintf('Instance settings file store: files.directory must be an absolute path (a relative one depends on the current directory of each process), got "%s".', $directory));
        }
        $this->assertNotUnderForbiddenRoot();
    }

    /**
     * Path of the field's current original when it is a valid image, else null. The seeder adopts it instead of
     * overwriting a committed file that no row references yet (audit JG-L5).
     */
    public function storedPath(string $section, string $field): ?string
    {
        return $this->current($section, $field) instanceof SettingFileRecord ? ($this->existingOriginal($section, $field)[0] ?? null) : null;
    }

    /**
     * Metadata of the field's current file (or of its variant) when its hash starts with `$hashPrefix`.
     */
    public function find(string $section, string $field, string $hashPrefix, int $variant): ?SettingFileRecord
    {
        $original = preg_match('/^[0-9a-f]{12,64}$/D', $hashPrefix) === 1 ? $this->current($section, $field) : null;
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
     * Bytes of a record ('' when the file changed or disappeared meanwhile: the file route then answers 404).
     */
    public function read(SettingFileRecord $record): string
    {
        $path  = $record->variant === 0 ? $this->existingOriginal($record->section, $record->field)[0] ?? null : $this->variantPath($record->section, $record->field, $record->sha256, $record->variant);
        $bytes = $path === null ? null : $this->readCapped($path);

        return $bytes === null || ($record->variant === 0 && hash('sha256', $bytes) !== $record->sha256) ? '' : $bytes;
    }

    /**
     * Stages (inside a unit) or writes the field's stable file, its variants and its metadata; the field's file of
     * another extension and the variants of another hash are removed once the new file is in place.
     *
     * @throws RuntimeException when a directory cannot be created or written, or a target is a symlink
     */
    public function save(string $section, string $field, ProcessedImage $image, DateTimeImmutable $at): void
    {
        $this->queue($this->stage($section, $field, $image));
    }

    /**
     * Clears the field's file (and variants): queued inside a unit (whatever its hash, unless the same unit saves a
     * new image for the field); outside a unit, deleted at once when it still holds the image `$sha256`.
     */
    public function orphan(string $section, string $field, string $sha256, DateTimeImmutable $at): void
    {
        $clear = [['clean', $section, $field, null, null]];
        if ($this->units === []) {
            $this->queue($this->current($section, $field)?->sha256 === $sha256 ? $clear : []);

            return;
        }
        foreach ($this->pending() as $unit) {
            foreach ($unit as $operation) {
                if ($operation[0] === 'clean' && $operation[1] === $section && $operation[2] === $field && $operation[3] !== null) {
                    return;
                }
            }
        }
        $this->queue($clear);
    }

    /**
     * Opens a (nested) unit.
     */
    public function begin(): void
    {
        $this->units[] = [];
    }

    /**
     * Ends the innermost unit: applies its changes when it was the outermost one and committed, hands them to the
     * outer unit when nested, removes its temporary files when rolled back.
     */
    public function end(bool $committed): void
    {
        $operations = array_pop($this->units) ?? [];
        if (!$committed) {
            $this->discard($operations);
        } else {
            $this->queue($operations);
        }
    }

    /**
     * Discards the staged changes of every open unit (kernel.reset: a worker request that died mid-transaction).
     */
    public function reset(): void
    {
        foreach ($this->pending() as $operations) {
            $this->discard($operations);
        }
        $this->units        = [];
        $this->transactions = [];
    }

    /**
     * A real database transaction started on `$connection` (DBAL middleware).
     */
    public function transactionStarted(object $connection): void
    {
        unset($this->transactions[spl_object_id($connection)]);
        $this->transactions[spl_object_id($connection)] = [[null, []]];
    }

    /**
     * The real transaction of `$connection` ended: a commit applies every operation it holds, a rollback discards them.
     */
    public function transactionEnded(object $connection, bool $committed): void
    {
        $levels = $this->transactions[spl_object_id($connection)] ?? [];
        unset($this->transactions[spl_object_id($connection)]);
        $operations = $this->flatten($levels);
        $committed ? $this->apply($operations) : $this->discard($operations);
    }

    /**
     * A savepoint was created on `$connection`: a new innermost level (a level of the same name still open was
     * implicitly committed into its parent, as on platforms that cannot release savepoints).
     */
    public function savepointCreated(object $connection, string $name): void
    {
        $id = spl_object_id($connection);
        if (!isset($this->transactions[$id])) {
            return;
        }
        $this->closeSavepoint($id, $name, true);
        $this->transactions[$id][] = [$name, []];
    }

    /**
     * A savepoint was released (committed) on `$connection`: its operations go to the enclosing level.
     */
    public function savepointReleased(object $connection, string $name): void
    {
        $this->closeSavepoint(spl_object_id($connection), $name, true);
    }

    /**
     * The connection rolled back to a savepoint: the operations of that level (and of the levels inside it) are
     * discarded (audit JG-M3).
     */
    public function savepointRolledBack(object $connection, string $name): void
    {
        $this->closeSavepoint(spl_object_id($connection), $name, false);
    }

    /**
     * No orphan marks on the filesystem (a cleared image is deleted at once): always 0.
     */
    public function markUnreferenced(array $referenced, DateTimeImmutable $at, bool $dryRun = false): int
    {
        return 0;
    }

    /**
     * Deletes the stale variants and metadata (of another hash than the field's current file, or of a field without a
     * file) and temporary files older than {@see self::TEMP_MAX_AGE}; never an original. Returns the number of files.
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
                    $path = $level . DIRECTORY_SEPARATOR . $name;
                    if ((str_starts_with($name, self::TEMP_PREFIX) && (int) @filemtime($path) < time() - self::TEMP_MAX_AGE) || ($level === $variants && $this->isStale($section, $name))) {
                        $stale[] = $path;
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
     * Writes the temporary files of a save and returns its operations (renames, metadata, clean-up).
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string|null, 4: string|null, 5?: array<string, int|string>}>
     */
    private function stage(string $section, string $field, ProcessedImage $image): array
    {
        $original  = $image->original;
        $extension = SettingFileUrls::EXTENSIONS[$original->mimeType] ?? null;
        if ($extension === null || !$this->validNames($section, $field)) {
            throw new RuntimeException(sprintf('Instance settings file store: invalid section / field name or type for "%s.%s".', $section, $field));
        }
        $sha        = $original->sha256();
        $operations = [];
        try {
            $directory = $this->directory($section);
            $target    = $directory . DIRECTORY_SEPARATOR . $field . '.' . $extension;
            if (!is_file($target) || is_link($target) || hash_file('sha256', $target) !== $sha) {
                $operations[] = ['write', $this->temp($directory, $target, $original->bytes), $target, null, null];
            }
            $variants = $this->directory($section, self::VARIANTS_DIR);
            foreach ($image->variants as $size => $encoded) {
                $path         = $variants . DIRECTORY_SEPARATOR . sprintf('%s-%s-%d.png', $field, substr($sha, 0, SettingFileUrls::HASH_LENGTH), $size);
                $operations[] = ['write', $this->temp($variants, $path, $encoded->bytes), $path, null, null];
            }
        } catch (Throwable $e) {
            $this->discard($operations);

            throw $e;
        }
        $operations[] = ['meta', $section, $field, $target, $sha, ['mime' => $original->mimeType, 'width' => $original->width, 'height' => $original->height]];
        $operations[] = ['clean', $section, $field, $target, $sha];

        return $operations;
    }

    /**
     * Applies the operations now (no unit open) or stages them in the innermost unit.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string|null, 4: string|null, 5?: array<string, int|string>}> $operations
     */
    private function queue(array $operations): void
    {
        $connection = array_key_last($this->transactions);
        if ($this->units === [] && $connection !== null) {
            $level                                      = count($this->transactions[$connection]) - 1;
            $this->transactions[$connection][$level][1] = [...$this->transactions[$connection][$level][1], ...$operations];

            return;
        }
        if ($this->units === []) {
            $this->apply($operations);

            return;
        }
        $last               = count($this->units) - 1;
        $this->units[$last] = [...$this->units[$last], ...$operations];
    }

    /**
     * Renames the staged files into place, writes the metadata and removes what a new file replaces (only once that
     * file is in place) or what a clear removes.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string|null, 4: string|null, 5?: array<string, int|string>}> $operations
     */
    private function apply(array $operations): void
    {
        foreach ($operations as $operation) {
            [$kind, $a, $b, $target, $sha] = $operation;
            if ($kind === 'write') {
                // A target turned into a symlink meanwhile is never written through; a failed rename keeps the old file.
                if (is_link($b) || !@rename($a, $b)) {
                    @unlink($a);
                }

                continue;
            }
            $placed = $target !== null && is_file($target) && !is_link($target) && hash_file('sha256', $target) === $sha;
            if ($kind === 'meta' && $placed) {
                $this->writeMeta($b, (string) $target, (string) $sha, $operation[5] ?? []);
            } elseif ($kind === 'clean' && ($target === null || $placed)) {
                $this->removeField($a, $b, $target, $sha);
            }
        }
    }

    /**
     * Removes the temporary files of discarded operations.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string|null, 4: string|null, 5?: array<string, int|string>}> $operations
     */
    private function discard(array $operations): void
    {
        foreach ($operations as $operation) {
            if ($operation[0] === 'write') {
                @unlink($operation[1]);
            }
        }
    }

    /**
     * Removes the field's originals (except `$keep`), its variants (except those of `$keepSha`) and, on a clear, its
     * metadata.
     */
    private function removeField(string $section, string $field, ?string $keep, ?string $keepSha): void
    {
        $directory = $this->root() . DIRECTORY_SEPARATOR . $section;
        foreach (SettingFileUrls::EXTENSIONS as $extension) {
            $path = $directory . DIRECTORY_SEPARATOR . $field . '.' . $extension;
            if ($path !== $keep && (is_file($path) || is_link($path))) {
                @unlink($path);
            }
        }
        foreach ($this->variantNames($section) as $name) {
            $own = preg_match(self::VARIANT_PATTERN, $name, $match) === 1 && $match[1] === $field && ($keepSha === null || !str_starts_with($keepSha, $match[2]));
            if ($own || ($keepSha === null && $name === $field . '.meta')) {
                @unlink($directory . DIRECTORY_SEPARATOR . self::VARIANTS_DIR . DIRECTORY_SEPARATOR . $name);
            }
        }
    }

    /**
     * A temporary file holding `$bytes` in `$directory` (0644 minus the umask); refused for a symlinked or directory
     * target, or when the directory is not writable.
     */
    private function temp(string $directory, string $target, string $bytes): string
    {
        if (is_link($target)) {
            throw new RuntimeException(sprintf('Instance settings file store: refusing to write through the symlink "%s".', $target));
        }
        $temp = @tempnam($directory, self::TEMP_PREFIX);
        // tempnam() falls back to the system temp directory when $directory is not writable: refuse that too.
        $ok = is_string($temp) && dirname((string) realpath($temp)) === $directory && @file_put_contents($temp, $bytes) === strlen($bytes) && @chmod($temp, 0o644 & ~umask()) && !is_dir($target);
        if (!$ok) {
            @unlink((string) $temp);

            throw new RuntimeException(sprintf('Instance settings file store: could not write "%s" (is the directory writable?).', $target));
        }

        return $temp;
    }

    /**
     * Writes the metadata of a stable file that is in place (its stat binds the metadata to that exact file). A
     * failure is ignored: without metadata the file is read and hashed on lookup.
     *
     * @param array<string, int|string> $image
     */
    private function writeMeta(string $field, string $target, string $sha, array $image): void
    {
        $stat = (array) @stat($target);
        $meta = (string) json_encode(['sha256' => $sha] + $image + ['size' => $stat['size'] ?? 0, 'mtime' => $stat['mtime'] ?? 0, 'ctime' => $stat['ctime'] ?? 0, 'ino' => $stat['ino'] ?? 0]);
        $path = dirname($target) . DIRECTORY_SEPARATOR . self::VARIANTS_DIR . DIRECTORY_SEPARATOR . $field . '.meta';
        try {
            $temp = $this->temp(dirname($path), $path, $meta);
            if (!@rename($temp, $path)) {
                @unlink($temp);
            }
        } catch (RuntimeException) {
            // Metadata is an optimisation only.
        }
    }

    /**
     * Closes the savepoint level `$name` and every level inside it: committed into the enclosing level, or discarded.
     */
    private function closeSavepoint(int $connection, string $name, bool $committed): void
    {
        $levels = $this->transactions[$connection] ?? [];
        foreach ($levels as $index => [$level]) {
            if ($index > 0 && $level !== null && strcasecmp($level, $name) === 0) {
                $closed = $this->flatten(array_slice($levels, $index));
                $levels = array_slice($levels, 0, $index);
                $parent = count($levels) - 1;
                if ($committed) {
                    $levels[$parent][1] = [...$levels[$parent][1], ...$closed];
                } else {
                    $this->discard($closed);
                }
                $this->transactions[$connection] = $levels;

                return;
            }
        }
    }

    /**
     * Every staged operation list: the open units and the levels of the open transactions.
     *
     * @return list<list<array{0: string, 1: string, 2: string, 3: string|null, 4: string|null, 5?: array<string, int|string>}>>
     */
    private function pending(): array
    {
        $lists = $this->units;
        foreach ($this->transactions as $levels) {
            foreach ($levels as [, $operations]) {
                $lists[] = $operations;
            }
        }

        return $lists;
    }

    /**
     * @param list<array{0: string|null, 1: list<array{0: string, 1: string, 2: string, 3: string|null, 4: string|null, 5?: array<string, int|string>}>}> $levels
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string|null, 4: string|null, 5?: array<string, int|string>}>
     */
    private function flatten(array $levels): array
    {
        $operations = [];
        foreach ($levels as [, $level]) {
            $operations = [...$operations, ...$level];
        }

        return $operations;
    }

    /**
     * The real directory of a section (or of its variants), created 0755 when missing; a symlink is refused, and so
     * is a base directory under one of the forbidden roots (checked before it is created).
     */
    private function directory(string $section, ?string $sub = null): string
    {
        // Again before any mkdir(): a symlink created since the store was built may now lead into a forbidden root.
        $this->assertNotUnderForbiddenRoot();
        if (!is_dir($this->base()) && !@mkdir($this->base(), 0o755, true)) {
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
        $last = $levels[count($levels) - 1];
        if ($sub === self::VARIANTS_DIR) {
            $this->ignoreInGit($last);
        }

        return $last;
    }

    /**
     * 1.7.1: makes a variants directory self-ignoring for git (a `.gitignore` holding `*`, which also ignores itself),
     * so the committed originals sit next to generated files that never show up in `git status`. Written once, when
     * missing (never over a file or through a symlink the host put there); a failure is ignored.
     */
    private function ignoreInGit(string $variants): void
    {
        $path = $variants . DIRECTORY_SEPARATOR . self::GITIGNORE;
        if (!is_link($path) && !file_exists($path)) {
            @file_put_contents($path, self::GITIGNORE_CONTENT);
        }
    }

    /**
     * @throws RuntimeException when the base directory resolves under one of the forbidden roots
     */
    private function assertNotUnderForbiddenRoot(): void
    {
        foreach ($this->forbiddenRoots as $root) {
            if (PathGuard::isLocalPath($root) && PathGuard::isUnder($this->directory, $root)) {
                throw new RuntimeException(sprintf('Instance settings file store: files.directory "%s" is under the public web root "%s": the web server would serve the images without the file route\'s access check and headers. Use a directory outside it, e.g. "%%kernel.project_dir%%/config/instance-files".', $this->directory, $root));
            }
        }
    }

    /**
     * Metadata of the field's current original, or null (no file, a symlink, too large, not a PNG / JPEG / WebP by its
     * content, or a content type that disagrees with the extension). Uses the metadata file while it matches the file.
     */
    private function current(string $section, string $field): ?SettingFileRecord
    {
        [$path, $extension] = $this->existingOriginal($section, $field) ?? [null, null];
        $meta               = $path === null ? null : $this->meta($section, $field, $path, (string) $extension);
        if ($meta !== null) {
            return new SettingFileRecord(0, $section, $field, (string) $meta['sha256'], 0, (string) $meta['mime'], (int) $meta['width'], (int) $meta['height'], (int) $meta['size']);
        }
        $bytes = $path === null ? null : $this->readCapped($path);
        $mime  = $bytes === null ? null : ImageProcessor::sniff($bytes);
        $info  = $mime === null || SettingFileUrls::EXTENSIONS[$mime] !== $extension ? false : @getimagesizefromstring((string) $bytes);
        if ($info === false) {
            return null;
        }

        return new SettingFileRecord(0, $section, $field, hash('sha256', (string) $bytes), 0, (string) $mime, $info[0], $info[1], strlen((string) $bytes));
    }

    /**
     * The field's metadata when it describes exactly the file at `$path` (same size, mtime, ctime and inode; a type of
     * that extension), else null.
     *
     * @return array<string, mixed>|null
     */
    private function meta(string $section, string $field, string $path, string $extension): ?array
    {
        $file = $this->root() . DIRECTORY_SEPARATOR . $section . DIRECTORY_SEPARATOR . self::VARIANTS_DIR . DIRECTORY_SEPARATOR . $field . '.meta';
        $meta = is_file($file) && !is_link($file) ? json_decode((string) @file_get_contents($file, false, null, 0, 4096), true) : null;
        $stat = @stat($path);
        if (!is_array($meta) || !is_array($stat) || !is_string($meta['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $meta['sha256']) !== 1 || !is_string($meta['mime'] ?? null) || (SettingFileUrls::EXTENSIONS[$meta['mime']] ?? null) !== $extension) {
            return null;
        }
        foreach (['width' => null, 'height' => null, 'size' => $stat['size'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime'], 'ino' => $stat['ino']] as $key => $expected) {
            if (!is_int($meta[$key] ?? null) || ($expected !== null && $expected !== $meta[$key])) {
                return null;
            }
        }

        return $meta;
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
     * Whether a file of a variants directory is a variant of another hash than its field's current file (or of a
     * field without a file), or the metadata of a field without a file; other names are left alone.
     */
    private function isStale(string $section, string $name): bool
    {
        if (preg_match(self::VARIANT_PATTERN, $name, $match) === 1) {
            return !str_starts_with($this->current($section, $match[1])->sha256 ?? '', $match[2]);
        }

        return preg_match(self::META_PATTERN, $name, $match) === 1 && $this->existingOriginal($section, $match[1]) === null;
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
        $real = realpath($this->base());

        return $real !== false && is_dir($real) ? $real : null;
    }

    private function root(): string
    {
        return $this->existingRoot() ?? $this->base();
    }

    /**
     * The base directory normalised lexically (1.7.1): `a/missing/../files` is `a/files` for reads, writes and the
     * public-root check alike (as PHP's own `mkdir()` sees it), also while `missing` does not exist.
     */
    private function base(): string
    {
        return rtrim(PathGuard::resolve($this->directory), '/');
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
