<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Seed;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use JsonException;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\File\FileStoreTransaction;
use Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore;
use Nowo\InstanceSettingsBundle\File\ImageProcessor;
use Nowo\InstanceSettingsBundle\File\ImageRefusedException;
use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\File\ProcessedImage;
use Nowo\InstanceSettingsBundle\File\SettingFileStoreInterface;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionSaveFilterInterface;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Service\ResetInterface;

use function array_key_exists;
use function array_keys;
use function class_exists;
use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_readable;
use function is_string;
use function json_decode;
use function pathinfo;
use function preg_match;
use function realpath;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function stripos;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const PATHINFO_EXTENSION;

/**
 * Loads a host **seed file** (1.7, IS-3): the initial data of an instance — plain values and images — committed in the
 * host repository, applied **fill-only** (a field that already has a stored value, or a stored image, is never
 * touched). Fresh instances (dev, e2e, new installs) run it idempotently; a second run is a no-op.
 *
 * Format (YAML or JSON, by extension):
 *
 *     schema: nowo-instance-settings-seed
 *     version: 1
 *     settings:            # plain fields: section => field => scalar
 *       brand: { name: Beacon, eyebrow: Uptime }
 *       appearance: { preset: ocean, light_primary: '#0f766e' }
 *     files:               # image fields: section => field => path relative to the seed file
 *       brand: { logo_light: brand/logo-light.png, app_icon: brand/app-icon.png }
 *
 * - All-or-nothing validation **before any write**: unknown section / field, a secret field (never in a committed
 *   file), an image field under `settings` or a plain one under `files`, an invalid value, a refused path or image all
 *   throw a {@see SeedFileException} listing every problem (keys and reason codes, never values).
 * - Plain values go through the section save filter ({@see SettingsSectionSaveFilterInterface}) and the field
 *   validation ({@see ValueNormalizer}), as a save does.
 * - Image paths: relative to the seed file's directory only (no absolute path, URL, stream wrapper or `data:`),
 *   resolved with `realpath()` (symlinks followed) and refused outside the base directory (`seed.base_dir`); the bytes
 *   go through the same {@see ImageProcessor} checks as an upload (type sniffing, size, dimensions, square, animated
 *   WebP); SVG is refused with a clear message. Images are stored by the configured {@see SettingFileStoreInterface};
 *   with the filesystem store, a valid image that already sits at the field's stable path is adopted (re-encoded like an
 *   upload) instead of being overwritten; file changes are applied only after the commit.
 * - Insert-only in one transaction, re-checking each field there (a concurrent admin save wins); audited like a save
 *   (`Instance settings saved.` log line and {@see SettingsChangedEvent} with origin
 *   {@see SettingsChangedEvent::ORIGIN_SEED} and the file hashes) **after the commit**.
 *
 * Public service: host-side importers can call {@see self::seed()} with an array and {@see self::seedImage()} with a
 * local file path. Stateless (worker-safe).
 */
final readonly class SeedFileSeeder
{
    /** `schema` of a seed file. */
    public const SCHEMA = 'nowo-instance-settings-seed';

    /** Current (and only) seed format version. */
    public const VERSION = 1;

    /** Largest seed file (1 MiB; images are separate files). */
    public const MAX_SEED_FILE_BYTES = 1_048_576;

    /** Top-level keys of a seed file. */
    public const KEYS = ['schema', 'version', 'settings', 'files'];

    /** The section dropped the value (equal to its runtime default, e.g. a colour equal to the preset). */
    public const SKIP_DEFAULT = 'default';

    /** Attempts of a seed whose insert lost a unique-key race. */
    private const ATTEMPTS = 2;

    /**
     * @param string $baseDir directory every seed path must resolve into (`seed.base_dir`)
     * @param string|null $defaultFile seed file used when none is given (`seed.file`)
     * @param SettingFileStoreInterface|null $fileStore image storage (null: image fields cannot be seeded)
     */
    public function __construct(
        private SettingsSectionRegistry $registry,
        private SettingValueRepositoryInterface $repository,
        private InstanceSettingsInterface $settings,
        private string $baseDir,
        private ?string $defaultFile = null,
        private ?SettingFileStoreInterface $fileStore = null,
        private ImageProcessor $images = new ImageProcessor(),
        private ValueNormalizer $normalizer = new ValueNormalizer(),
        private ?LoggerInterface $logger = null,
        private ?TransactionStateGuard $transactionGuard = null,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * The configured seed file (`seed.file`), or null.
     */
    public function defaultFile(): ?string
    {
        return $this->defaultFile;
    }

    /**
     * Loads a seed file (YAML / JSON; `$file` null = `seed.file`) and seeds it fill-only.
     *
     * @param list<string>|null $sections only these sections of the file (null = all)
     *
     * @throws SeedFileException when the file or any of its entries is refused (nothing is written)
     */
    public function seedFile(?string $file = null, ?array $sections = null, bool $dryRun = false): SeedReport
    {
        $file ??= $this->defaultFile ?? throw SeedFileException::single('file', SeedFileException::NO_FILE);
        $path    = $this->resolve($file, null);
        $content = (string) @file_get_contents($path, false, null, 0, self::MAX_SEED_FILE_BYTES + 1);
        if (strlen($content) > self::MAX_SEED_FILE_BYTES) {
            throw SeedFileException::single('file', SeedFileException::FILE_TOO_LARGE);
        }
        $data = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'yaml', 'yml' => $this->parseYaml($content),
            'json'        => $this->parseJson($content),
            default       => throw SeedFileException::single('file', SeedFileException::UNSUPPORTED_FORMAT),
        };
        if (!is_array($data)) {
            throw SeedFileException::single('file', SeedFileException::PARSE_ERROR);
        }
        if (($data['schema'] ?? null) !== self::SCHEMA) {
            throw SeedFileException::single('schema', SeedFileException::INVALID_SCHEMA);
        }
        if (($data['version'] ?? null) !== self::VERSION) {
            throw SeedFileException::single('version', SeedFileException::UNSUPPORTED_VERSION);
        }

        return $this->run($data, dirname($path), $sections, $dryRun, false);
    }

    /**
     * Seeds seed data given as an array (the parsed seed file format; `schema` / `version` optional, checked when
     * present). Image paths are relative to `$directory`, which must resolve inside the base directory.
     *
     * @param array<mixed> $data `settings` and / or `files`
     * @param list<string>|null $sections only these sections (null = all)
     *
     * @throws SeedFileException when any entry is refused (nothing is written)
     */
    public function seed(array $data, string $directory, ?array $sections = null, bool $dryRun = false): SeedReport
    {
        if (array_key_exists('schema', $data) && $data['schema'] !== self::SCHEMA) {
            throw SeedFileException::single('schema', SeedFileException::INVALID_SCHEMA);
        }
        if (array_key_exists('version', $data) && $data['version'] !== self::VERSION) {
            throw SeedFileException::single('version', SeedFileException::UNSUPPORTED_VERSION);
        }

        return $this->run($data, $this->resolve($directory, null, true), $sections, $dryRun, false);
    }

    /**
     * Stores one image setting (`<section>.<field>`) from a local file, **fill-only**: nothing happens when the field
     * already holds an image. `$path` is absolute or relative to the base directory and must resolve inside it. Same
     * checks, storage and audit as a seed file entry.
     *
     * @throws SeedFileException when the key, the path or the image is refused
     */
    public function seedImage(string $key, string $path, bool $dryRun = false): SeedReport
    {
        $parts = explode('.', $key, 2);

        return $this->run(['files' => [$parts[0] => [$parts[1] ?? '' => $path]]], (string) realpath($this->baseDir), null, $dryRun, true);
    }

    /**
     * @param array<mixed> $data
     * @param list<string>|null $sections
     */
    private function run(array $data, string $directory, ?array $sections, bool $dryRun, bool $absolutePaths): SeedReport
    {
        $errors = [];
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                $errors[] = SeedFileException::line((string) $key, SeedFileException::UNKNOWN_KEY);
            }
        }
        foreach ($sections ?? [] as $section) {
            if (!$this->registry->has($section)) {
                $errors[] = SeedFileException::line($section, SeedFileException::UNKNOWN_SECTION);
            }
        }
        $entries = [];
        foreach (['settings', 'files'] as $kind) {
            $tree = $data[$kind] ?? [];
            if (!is_array($tree)) {
                $errors[] = SeedFileException::line($kind, SeedFileException::NOT_A_MAPPING);

                continue;
            }
            foreach ($tree as $section => $fields) {
                $section = (string) $section;
                if (!$this->registry->has($section)) {
                    $errors[] = SeedFileException::line($section, SeedFileException::UNKNOWN_SECTION);
                } elseif (!is_array($fields)) {
                    $errors[] = SeedFileException::line($kind . '.' . $section, SeedFileException::NOT_A_MAPPING);
                } elseif ($sections === null || in_array($section, $sections, true)) {
                    foreach ($fields as $field => $value) {
                        $entries[$section][$kind][(string) $field] = $value;
                    }
                }
            }
        }

        $plans   = [];
        $skipped = [];
        foreach ($entries as $section => $kinds) {
            [$plan, $sectionSkipped, $sectionErrors] = $this->plan($section, $kinds['settings'] ?? [], $kinds['files'] ?? [], $directory, $absolutePaths);
            $errors                                  = [...$errors, ...$sectionErrors];
            $skipped += $sectionSkipped;
            $plans[$section] = $plan;
        }
        if ($errors !== []) {
            throw new SeedFileException($errors);
        }

        if (!$dryRun) {
            $this->transactionGuard?->check();
        }
        $attempt = 0;
        while (true) {
            try {
                [$seeded, $lost, $events] = $dryRun ? [$this->plannedKeys($plans), [], []] : FileStoreTransaction::run($this->repository, $this->fileStore, fn (): array => $this->insertMissing($plans));
                break;
            } catch (UniqueConstraintViolationException $e) {
                if (++$attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }
        $report = new SeedReport(array_keys($entries), $seeded, $skipped + $lost, [], $dryRun);

        if ($events !== [] && $this->settings instanceof ResetInterface) {
            $this->settings->reset();
        }
        foreach ($events as [$event, $files]) {
            $context = [
                'bundle'  => 'NowoInstanceSettingsBundle',
                'section' => $event->section,
                'fields'  => implode(',', $event->changedFields),
                'origin'  => SettingsChangedEvent::ORIGIN_SEED,
            ];
            if ($files !== '') {
                $context['files'] = $files;
            }
            $this->logger?->info('Instance settings saved.', $context);
            $this->dispatcher?->dispatch($event);
        }
        $this->logger?->info($dryRun ? 'Instance settings seed file checked (dry run).' : 'Instance settings seeded from a seed file.', [
            'bundle'   => 'NowoInstanceSettingsBundle',
            'sections' => implode(',', $report->sections),
            'seeded'   => implode(',', $report->seeded),
            'skipped'  => implode(',', array_keys($report->skipped)),
            'origin'   => SettingsChangedEvent::ORIGIN_SEED,
        ]);

        return $report;
    }

    /**
     * Validated plan of one section: plain values and processed images of the fields without a stored row.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $files
     *
     * @return array{0: array{values: array<string, bool|float|int|string>, images: array<string, array{0: ProcessedImage, 1: string, 2: string}>}, 1: array<string, string>, 2: list<string>}
     */
    private function plan(string $section, array $values, array $files, string $directory, bool $absolutePaths): array
    {
        $fields = $this->registry->fields($section);
        $stored = [];
        foreach ($this->repository->findBySection($section) as $row) {
            $stored[$row->getName()] = true;
        }
        $plan    = ['values' => [], 'images' => []];
        $skipped = [];
        $errors  = [];

        $plain = [];
        foreach ($values as $name => $value) {
            $error = $this->fieldError($fields[$name] ?? null, false) ?? (is_array($value) ? SeedFileException::NOT_SCALAR : null);
            if ($error !== null) {
                $errors[] = SeedFileException::line($section . '.' . $name, $error);
            } else {
                $plain[$name] = $value;
            }
        }
        $definition = $this->registry->get($section);
        $filtered   = $definition instanceof SettingsSectionSaveFilterInterface ? $definition->filterValues($plain, $this->settings->getSection($section)) : $plain;
        foreach ($plain as $name => $raw) {
            $key                = $section . '.' . $name;
            [$clean, $messages] = $this->normalizer->normalize($fields[$name], $filtered[$name] ?? null, false);
            if ($messages !== []) {
                $errors[] = SeedFileException::line($key, SeedFileException::INVALID_VALUE);
            } elseif ($clean === null) {
                $skipped[$key] = $this->isBlank($raw) ? SeedReport::SKIP_EMPTY : self::SKIP_DEFAULT;
            } elseif (isset($stored[$name])) {
                $skipped[$key] = SeedReport::SKIP_STORED;
            } else {
                $plan['values'][$name] = $clean;
            }
        }

        foreach ($files as $name => $path) {
            $key   = $section . '.' . $name;
            $field = $fields[$name] ?? null;
            $error = $this->fieldError($field, true) ?? ($this->fileStore instanceof SettingFileStoreInterface ? null : SeedFileException::FILES_DISABLED);
            if ($error !== null) {
                $errors[] = SeedFileException::line($key, $error);

                continue;
            }
            if ($path === null || (is_string($path) && trim($path) === '')) {
                $skipped[$key] = SeedReport::SKIP_EMPTY;

                continue;
            }
            try {
                $real = $this->resolve(is_string($path) ? $path : '', $directory, $absolutePaths);
                // Filesystem store: a valid committed file already at the field's stable path is adopted (re-encoded
                // like an upload) instead of being overwritten by another file (audit JG-L5).
                $source = $this->fileStore instanceof FilesystemSettingFileStore && !isset($stored[$name]) ? $this->fileStore->storedPath($section, $name) ?? $real : $real;
                $image  = $this->image($field->image ?? new ImageSpec(), $source);
            } catch (SeedFileException $e) {
                $errors[] = SeedFileException::line($key, $e->reason ?? SeedFileException::INVALID_VALUE);

                continue;
            } catch (ImageRefusedException $e) {
                $errors[] = SeedFileException::line($key, $e->reason(), $e->describe());

                continue;
            }
            if (isset($stored[$name])) {
                $skipped[$key] = SeedReport::SKIP_STORED;
            } else {
                $plan['images'][$name] = [$image[0], $image[1], $real];
            }
        }

        return [$plan, $skipped, $errors];
    }

    /**
     * Reason a field cannot be seeded under `settings` (`$image` false) or `files` (`$image` true), or null.
     */
    private function fieldError(?SettingField $field, bool $image): ?string
    {
        if (!$field instanceof SettingField) {
            return SeedFileException::UNKNOWN_FIELD;
        }

        return match (true) {
            $field->secret                                 => SeedFileException::SECRET_FIELD,
            $image && $field->type !== SettingType::Image  => SeedFileException::NOT_AN_IMAGE_FIELD,
            !$image && $field->type === SettingType::Image => SeedFileException::IMAGE_FIELD_IN_SETTINGS,
            default                                        => null,
        };
    }

    /**
     * Reads (capped) and processes an image file; SVG gets its own refusal.
     *
     * @throws ImageRefusedException|SeedFileException
     *
     * @return array{0: ProcessedImage, 1: string} processed image and the file bytes
     */
    private function image(ImageSpec $spec, string $path): array
    {
        $bytes = ImageUpload::fromPath($path)->read($spec->maxBytes);
        if (ImageProcessor::sniff($bytes) === null && (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg' || stripos(substr($bytes, 0, 4096), '<svg') !== false)) {
            throw SeedFileException::single('file', SeedFileException::SVG_NOT_SUPPORTED);
        }

        return [$this->images->process($spec, $bytes), $bytes];
    }

    /**
     * Real path of a seed path: relative to `$directory` (null: the current directory, for the seed file itself), or
     * absolute when `$absolute`; never a URL / stream wrapper; inside the base directory; a readable regular file (or
     * directory when `$directory` is null and `$absolute`).
     *
     * @throws SeedFileException
     */
    private function resolve(string $path, ?string $directory, bool $absolute = false): string
    {
        $relative = $directory !== null && !$absolute;
        if (trim($path) === '' || str_contains($path, "\0") || str_contains($path, '://') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1
            || ($relative && (str_starts_with($path, '/') || str_starts_with($path, '\\')))) {
            throw SeedFileException::single('file', SeedFileException::PATH_INVALID);
        }
        $candidate = $directory === null || str_starts_with($path, '/') ? $path : $directory . DIRECTORY_SEPARATOR . $path;
        $real      = realpath($candidate);
        $base      = realpath($this->baseDir);
        if ($real === false) {
            throw SeedFileException::single('file', SeedFileException::PATH_NOT_FOUND);
        }
        if ($base === false || ($real !== $base && !str_starts_with($real, $base . DIRECTORY_SEPARATOR))) {
            throw SeedFileException::single('file', SeedFileException::PATH_OUTSIDE_BASE);
        }
        $wantDirectory = $directory === null && $absolute;
        if ($wantDirectory ? !is_dir($real) : (!is_file($real) || !is_readable($real))) {
            throw SeedFileException::single('file', SeedFileException::NOT_A_FILE);
        }

        return $real;
    }

    /**
     * Inserts the planned rows (and stores the images) whose field is still empty, re-checked inside the transaction.
     *
     * @param array<string, array{values: array<string, bool|float|int|string>, images: array<string, array{0: ProcessedImage, 1: string, 2: string}>}> $plans
     *
     * @return array{0: list<string>, 1: array<string, string>, 2: list<array{0: SettingsChangedEvent, 1: string}>}
     */
    private function insertMissing(array $plans): array
    {
        $now    = $this->clock?->now() ?? new DateTimeImmutable();
        $seeded = [];
        $lost   = [];
        $events = [];
        foreach ($plans as $section => $plan) {
            $stored = [];
            foreach ($this->repository->findBySection($section) as $row) {
                $stored[$row->getName()] = true;
            }
            $rows    = [];
            $changed = [];
            $files   = [];
            $logged  = [];
            foreach ($plan['values'] + $plan['images'] as $name => $value) {
                $key = $section . '.' . $name;
                if (isset($stored[$name])) {
                    $lost[$key] = SeedReport::SKIP_STORED;

                    continue;
                }
                $row = new SettingValue($section, $name, $now);
                if (is_array($value)) {
                    $image        = $value[0];
                    $sha          = $this->storeImage($section, $name, $image, $now);
                    $rows[]       = $row->storeValue(SettingField::IMAGE_REFERENCE_PREFIX . $sha, $now);
                    $files[$name] = ['old' => null, 'new' => $sha];
                    $logged[]     = sprintf('%s=%s %s %dx%d %dB', $name, substr($sha, 0, 12), $image->original->mimeType, $image->original->width, $image->original->height, $image->original->byteSize());
                } else {
                    $rows[] = $row->storeValue($value, $now);
                }
                $changed[] = $name;
                $seeded[]  = $key;
            }
            if ($rows !== []) {
                $this->repository->apply($rows, []);
                $events[] = [new SettingsChangedEvent($section, $changed, [], SettingsChangedEvent::ORIGIN_SEED, $files), implode('; ', $logged)];
            }
        }

        return [$seeded, $lost, $events];
    }

    /**
     * Stores the processed image with the file store and returns the hash of the stored reference.
     */
    private function storeImage(string $section, string $field, ProcessedImage $image, DateTimeImmutable $now): string
    {
        $store = $this->fileStore ?? throw new LogicException('Instance settings image fields need a file store (SettingFileStoreInterface).');
        $store->save($section, $field, $image, $now);

        return $image->sha256();
    }

    /**
     * @param array<string, array{values: array<string, mixed>, images: array<string, mixed>}> $plans
     *
     * @return list<string>
     */
    private function plannedKeys(array $plans): array
    {
        $keys = [];
        foreach ($plans as $section => $plan) {
            foreach (array_keys($plan['values'] + $plan['images']) as $name) {
                $keys[] = $section . '.' . $name;
            }
        }

        return $keys;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function parseYaml(string $content): mixed
    {
        try {
            return class_exists(Yaml::class) ? Yaml::parse($content) : throw SeedFileException::single('file', SeedFileException::YAML_UNAVAILABLE);
        } catch (ParseException) {
            throw SeedFileException::single('file', SeedFileException::PARSE_ERROR);
        }
    }

    private function parseJson(string $content): mixed
    {
        try {
            return json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SeedFileException::single('file', SeedFileException::PARSE_ERROR);
        }
    }
}
