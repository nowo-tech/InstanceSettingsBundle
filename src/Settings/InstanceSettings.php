<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Settings;

use DateTimeImmutable;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\EventListener\WorkerBoundaryResetListener;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\File\FileStoreTransaction;
use Nowo\InstanceSettingsBundle\File\ImageProcessor;
use Nowo\InstanceSettingsBundle\File\ImageRefusedException;
use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\File\ProcessedImage;
use Nowo\InstanceSettingsBundle\File\SettingFileStoreInterface;
use Nowo\InstanceSettingsBundle\File\SettingFileUrls;
use Nowo\InstanceSettingsBundle\File\StoredSettingFile;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionSaveFilterInterface;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use WeakReference;

use function array_key_exists;
use function implode;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;
use function substr;
use function trim;

/**
 * Default {@see InstanceSettingsInterface}: one query per request for every row, memoized for the current main request
 * only (a new main request always reloads, even when `kernel.reset` is not called between worker requests) and cleared
 * by {@see reset()} and after every save. Outside HTTP (Messenger message, console command) the memo lives for one unit
 * of work: {@see WorkerBoundaryResetListener} calls {@see reset()} at every boundary, also when the services resetter
 * does not run.
 */
final class InstanceSettings implements InstanceSettingsInterface, ResetInterface
{
    /** @var array<string, array<string, SettingValue>>|null section => field => row */
    private ?array $rows = null;

    /** @var WeakReference<Request>|null main request the memo belongs to (null outside HTTP) */
    private ?WeakReference $rowsRequest = null;

    /** @var array<string, StoredSettingFile|null> `<section>.<field>` => file metadata, cleared with the rows memo */
    private array $files = [];

    /**
     * @param SettingValueRepositoryInterface $repository storage
     * @param SettingsSectionRegistry $registry registered sections
     * @param ValueNormalizer $normalizer value normalization and validation
     * @param EventDispatcherInterface|null $dispatcher receives {@see SettingsChangedEvent}
     * @param RequestStack|null $requestStack binds the memo to the main request
     * @param ClockInterface|null $clock `updated_at` source
     * @param LoggerInterface|null $logger logs section + field names of each save (never values)
     * @param SettingFileStoreInterface|null $fileStore image field storage (1.4; required to save an image)
     * @param ImageProcessor $images image sniffing, limits and re-encoding (1.4)
     * @param SettingFileUrls $fileUrls file route URLs (1.4)
     */
    public function __construct(
        private readonly SettingValueRepositoryInterface $repository,
        private readonly SettingsSectionRegistry $registry,
        private readonly ValueNormalizer $normalizer = new ValueNormalizer(),
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?SettingFileStoreInterface $fileStore = null,
        private readonly ImageProcessor $images = new ImageProcessor(),
        private readonly SettingFileUrls $fileUrls = new SettingFileUrls(),
    ) {
    }

    /**
     * Effective plain value (stored, else default); always null for an image field (read it with getFile()).
     */
    public function get(string $key): bool|float|int|string|null
    {
        [$section, $name, $field] = $this->registry->resolve($key);
        if ($field->secret) {
            throw new LogicException(sprintf('Instance setting "%s" is a secret: read it with getSecret().', $key));
        }

        return $field->type === SettingType::Image ? null : $this->effective($section, $name, $field);
    }

    /**
     * Stored image of an image field, or null (nothing stored, or the file is gone).
     */
    public function getFile(string $key): ?StoredSettingFile
    {
        [$section, $name, $field] = $this->registry->resolve($key);
        if ($field->image === null) {
            throw new LogicException(sprintf('Instance setting "%s" is not an image field.', $key));
        }
        $sha = self::imageHash($this->effective($section, $name, $field));
        if ($sha === null || !$this->fileStore instanceof SettingFileStoreInterface) {
            return null;
        }
        if (array_key_exists($key, $this->files)) {
            return $this->files[$key];
        }

        $records  = $this->fileStore->findImage($section, $name, $sha);
        $original = $records[0] ?? null;
        $file     = null;
        if ($original !== null) {
            // The store's hash: the reference for the database store; the current file for the filesystem store (1.7),
            // whose file on disk is authoritative.
            $sha  = $original->sha256;
            $urls = [0 => $this->fileUrls->path($section, $name, $sha, $original->mimeType)];
            foreach ($field->image->variants as $size) {
                if (isset($records[$size])) {
                    $urls[$size] = $this->fileUrls->path($section, $name, $sha, 'image/png', $size);
                }
            }
            $file = new StoredSettingFile($section, $name, $sha, $original->mimeType, $original->width, $original->height, $original->byteSize, $field->image->public, $urls);
        }

        return $this->files[$key] = $file;
    }

    /**
     * SHA-256 of a stored `isf:` reference, or null.
     */
    public static function imageHash(mixed $value): ?string
    {
        return is_string($value) && preg_match(SettingField::IMAGE_REFERENCE_PATTERN, $value) === 1 ? substr($value, 4) : null;
    }

    /**
     * String value; throws when the field is not string-typed.
     */
    public function getString(string $key): ?string
    {
        $value = $this->get($key);
        if ($value !== null && !is_string($value)) {
            throw new LogicException(sprintf('Instance setting "%s" is not a string.', $key));
        }

        return $value;
    }

    /**
     * Int value; throws when the field is not int-typed.
     */
    public function getInt(string $key): ?int
    {
        $value = $this->get($key);
        if ($value !== null && !is_int($value)) {
            throw new LogicException(sprintf('Instance setting "%s" is not an int.', $key));
        }

        return $value;
    }

    /**
     * Float value (ints widen); throws for other types.
     */
    public function getFloat(string $key): ?float
    {
        $value = $this->get($key);
        if ($value !== null && !is_float($value) && !is_int($value)) {
            throw new LogicException(sprintf('Instance setting "%s" is not a number.', $key));
        }

        return $value === null ? null : (float) $value;
    }

    /**
     * Bool value (null = false); throws when the field is not a bool.
     */
    public function getBool(string $key): bool
    {
        [, , $field] = $this->registry->resolve($key);
        if ($field->type !== SettingType::Bool) {
            throw new LogicException(sprintf('Instance setting "%s" is not a bool.', $key));
        }

        return $this->get($key) === true;
    }

    /**
     * Decrypted secret or null.
     */
    public function getSecret(string $key): ?string
    {
        [$section, $name, $field] = $this->registry->resolve($key);
        if (!$field->secret) {
            throw new LogicException(sprintf('Instance setting "%s" is not a secret: read it with get().', $key));
        }

        $secret = ($this->rows()[$section][$name] ?? null)?->getSecret();

        return $secret === null || $secret === '' ? null : $secret;
    }

    /**
     * Whether a secret is stored.
     */
    public function hasSecret(string $key): bool
    {
        return $this->getSecret($key) !== null;
    }

    /**
     * Whether a row is stored for the field.
     */
    public function isOverridden(string $key): bool
    {
        [$section, $name] = $this->registry->resolve($key);

        return isset($this->rows()[$section][$name]);
    }

    /**
     * Effective plain values of a section (no secrets, no image fields).
     */
    public function getSection(string $section): array
    {
        $values = [];
        foreach ($this->registry->fields($section) as $name => $field) {
            if (!$field->secret && $field->type !== SettingType::Image) {
                $values[$name] = $this->effective($section, $name, $field);
            }
        }

        return $values;
    }

    /**
     * Validates every value first, then upserts / removes the changed rows with one flush.
     */
    public function save(string $section, array $values, string $origin = SettingsChangedEvent::ORIGIN_API): array
    {
        $fields     = $this->registry->fields($section);
        $definition = $this->registry->get($section);
        if ($definition instanceof SettingsSectionSaveFilterInterface) {
            // 1.5: the section may rewrite its values (lower-case colours, drop a value equal to its runtime default).
            $values = $definition->filterValues($values, $this->getSection($section));
        }
        $normalized  = [];
        $imageValues = [];
        $violations  = [];
        $required    = [];
        // Pass 1: normalize every value; `required` is decided in pass 2, once the controlling values are normalized.
        foreach ($values as $name => $value) {
            $field = $this->registry->field($section, (string) $name);
            if ($field->type === SettingType::Image) {
                [$image, $messages] = $this->normalizeImage($section, $field, $value);
                if ($messages !== []) {
                    $violations[$field->name] = $messages;

                    continue;
                }
                $imageValues[$field->name] = $image;
                if ($image === null) {
                    $required[$field->name] = $value;
                }

                continue;
            }
            [$clean, $messages] = $this->normalizer->normalize($field, $value, false);
            if ($messages !== []) {
                $violations[$field->name] = $messages;

                continue;
            }
            $normalized[$field->name] = $clean;
            if ($clean === null) {
                $required[$field->name] = $value;
            }
        }
        // Pass 2: `required` only applies to a provider-dependent field visible for the normalized saved values merged
        // over the effective stored ones (1.3); a value saved empty takes its default, as it will once its row is
        // deleted (1.3.1).
        $context = null;
        foreach ($required as $name => $value) {
            $field   = $fields[$name];
            $visible = true;
            if ($field->visibleWhen !== null) {
                $context ??= $this->visibilityContext($section, $normalized);
                $visible = $field->isVisibleFor($context);
            }
            [, $messages] = $this->normalizer->normalize($field, $value, $visible);
            if ($messages !== []) {
                $violations[$name] = $messages;
            }
        }
        if ($violations !== []) {
            throw new InvalidSettingValueException($section, $violations);
        }
        $existing = [];
        foreach ($this->repository->findBySection($section) as $row) {
            $existing[$row->getName()] = $row;
        }
        // 1.12: a colour equal to its preset (default, or the colour of the submitted / stored preset source value) is
        // not stored, so a later change of the preset still reaches it. A colour re-submitted unchanged (the stored
        // value) is kept as is (audit LM-M1: an untouched save never changes a stored colour).
        $presetContext = null;
        foreach ($normalized as $name => $value) {
            if ($value !== null && $fields[$name]->type === SettingType::Color && ($existing[$name] ?? null)?->getValue() !== $value) {
                $presetContext ??= $this->visibilityContext($section, $normalized);
                if ($value === $fields[$name]->presetColor($presetContext)) {
                    $normalized[$name] = null;
                }
            }
        }

        $now         = $this->clock?->now() ?? (new NativeClock())->now();
        $save        = [];
        $remove      = [];
        $changed     = [];
        $secrets     = [];
        $fileChanges = [];
        $newImages   = [];
        $orphans     = [];
        foreach ($normalized as $name => $value) {
            $field = $fields[$name];
            $row   = $existing[$name] ?? null;
            if ($value === null) {
                if ($row !== null) {
                    $remove[]  = $row;
                    $changed[] = $name;
                }

                continue;
            }

            if ($field->secret) {
                if ($row !== null && $row->getSecret() === $value) {
                    continue;
                }
                $save[] = ($row ?? new SettingValue($section, $name, $now))->storeSecret((string) $value, $now);
            } else {
                if ($row !== null && $row->getSecretValue() === null && $row->getValue() === $value) {
                    continue;
                }
                $save[] = ($row ?? new SettingValue($section, $name, $now))->storeValue($value, $now);
            }
            $changed[] = $name;
        }

        foreach ($imageValues as $name => $value) {
            $row = $existing[$name] ?? null;
            $old = $row === null ? null : self::imageHash($row->getValue());
            $new = $value instanceof ProcessedImage ? $value->sha256() : null;
            if (is_string($value) || ($row === null && $value === null) || ($new !== null && $new === $old && $this->holds($section, $name, $new))) {
                // Unchanged reference, nothing to remove, or the same image uploaded again while the store holds it
                // (1.7, audit JG-M2: after a store switch the same image is written again).
                continue;
            }
            if ($row !== null && $value === null) {
                $remove[] = $row;
            } else {
                $newImages[$name] = $value;
                $save[]           = ($row ?? new SettingValue($section, $name, $now))->storeValue(SettingField::IMAGE_REFERENCE_PREFIX . $new, $now);
            }
            if ($old !== null && $old !== $new) {
                $orphans[] = [$name, $old];
            }
            $changed[]          = $name;
            $fileChanges[$name] = ['old' => $old, 'new' => $new];
        }

        foreach ($changed as $name) {
            if ($fields[$name]->secret) {
                $secrets[] = $name;
            }
        }

        if ($changed === []) {
            return [];
        }

        if ($fileChanges === []) {
            $this->repository->apply($save, $remove);
        } else {
            $this->applyWithFiles($section, $save, $remove, $newImages, $orphans, $now);
        }
        $this->reset();

        $context = [
            'bundle'  => 'NowoInstanceSettingsBundle',
            'section' => $section,
            'fields'  => implode(',', $changed),
            'origin'  => $origin,
        ];
        if ($fileChanges !== []) {
            $context['files'] = $this->describeFiles($fileChanges, $newImages);
        }
        $this->logger?->info('Instance settings saved.', $context);
        $this->dispatcher?->dispatch(new SettingsChangedEvent($section, $changed, $secrets, $origin, $fileChanges));

        return $changed;
    }

    /**
     * Effective values after a save: the normalized saved values (an empty one replaced by its field default) merged
     * over the effective stored ones.
     *
     * @param array<string, bool|float|int|string|null> $normalized
     *
     * @return array<string, mixed>
     */
    private function visibilityContext(string $section, array $normalized): array
    {
        $fields = $this->registry->fields($section);
        foreach ($normalized as $name => $value) {
            if ($value === null) {
                $normalized[$name] = $fields[$name]->default;
            }
        }

        return $normalized + $this->getSection($section);
    }

    /**
     * Clears the request memo (kernel.reset and after saves).
     */
    public function reset(): void
    {
        // Reset every memoized property here (not only through a helper) so the worker-state audit can see it.
        $this->rows        = null;
        $this->rowsRequest = null;
        $this->files       = [];
    }

    /**
     * Image input of a save: null / '' (remove), the current `isf:` reference (keep), or an upload ({@see ImageUpload}
     * or {@see UploadedFile}) checked and re-encoded. Anything else, including another reference, is refused: a field
     * can only point to an image uploaded or imported into it.
     *
     * @return array{0: ProcessedImage|string|null, 1: list<string>}
     */
    private function normalizeImage(string $section, SettingField $field, mixed $value): array
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return [null, []];
        }
        if (is_string($value)) {
            $current = $this->rows()[$section][$field->name] ?? null;

            return $current !== null && $current->getValue() === $value && self::imageHash($value) !== null ? [$value, []] : [null, ['This value is not a valid image.']];
        }
        if ($value instanceof UploadedFile) {
            $value = ImageUpload::fromUploadedFile($value);
        }
        if (!$value instanceof ImageUpload || !$field->image instanceof ImageSpec) {
            return [null, ['This value is not a valid image.']];
        }

        try {
            return [$this->images->process($field->image, $value->read($field->image->maxBytes)), []];
        } catch (ImageRefusedException $e) {
            return [null, [$e->describe()]];
        }
    }

    /**
     * Writes the image files, orphans the replaced ones and applies the rows in one transaction.
     *
     * @param list<SettingValue> $save
     * @param list<SettingValue> $remove
     * @param array<string, ProcessedImage|null> $newImages
     * @param list<array{0: string, 1: string}> $orphans field => replaced hash
     */
    private function applyWithFiles(string $section, array $save, array $remove, array $newImages, array $orphans, DateTimeImmutable $now): void
    {
        $store = $this->fileStore ?? throw new LogicException('Instance settings image fields need a file store (SettingFileStoreInterface).');
        // @igor-ignore - The closure runs synchronously inside the transaction and is not stored by the repository.
        // A staged (filesystem) store applies its file changes only after the outermost commit (1.7, audit JG-M1).
        FileStoreTransaction::run($this->repository, $store, function () use ($store, $section, $save, $remove, $newImages, $orphans, $now): void {
            foreach ($newImages as $name => $image) {
                if ($image instanceof ProcessedImage) {
                    $store->save($section, $name, $image, $now);
                }
            }
            foreach ($orphans as [$name, $sha]) {
                $store->orphan($section, $name, $sha, $now);
            }
            $this->repository->apply($save, $remove);
        });
    }

    /**
     * Whether the store holds the original of image `$sha256` for the field.
     */
    private function holds(string $section, string $field, string $sha256): bool
    {
        return ($this->fileStore?->findImage($section, $field, $sha256)[0] ?? null)?->sha256 === $sha256;
    }

    /**
     * Log context of the image changes: field, hash prefix, type, dimensions and size; never content.
     *
     * @param array<string, array{old: string|null, new: string|null}> $fileChanges
     * @param array<string, ProcessedImage|null> $newImages
     */
    private function describeFiles(array $fileChanges, array $newImages): string
    {
        $parts = [];
        foreach ($fileChanges as $name => $change) {
            $image   = $newImages[$name] ?? null;
            $parts[] = $name . '=' . ($image instanceof ProcessedImage
                ? sprintf('%s %s %dx%d %dB', substr($image->sha256(), 0, 12), $image->original->mimeType, $image->original->width, $image->original->height, $image->original->byteSize())
                : 'removed');
        }

        return implode('; ', $parts);
    }

    private function effective(string $section, string $name, SettingField $field): bool|float|int|string|null
    {
        $row = $this->rows()[$section][$name] ?? null;
        if ($row === null) {
            return $field->default;
        }

        $value = $row->getValue();

        // A stored value that no longer matches the field (type changed in code) falls back to the default.
        return $value !== null && SettingField::matchesType($field->type, $value, $field->choices) ? $value : $field->default;
    }

    /**
     * @return array<string, array<string, SettingValue>>
     */
    private function rows(): array
    {
        $request = $this->requestStack?->getMainRequest();
        if ($this->rows !== null && $this->isMemoizedFor($request)) {
            return $this->rows;
        }

        $this->files = [];
        $rows        = [];
        foreach ($this->repository->findAll() as $row) {
            $rows[$row->getSection()][$row->getName()] = $row;
        }

        $this->rowsRequest = $request instanceof Request ? WeakReference::create($request) : null;

        return $this->rows = $rows;
    }

    private function isMemoizedFor(?Request $request): bool
    {
        if (!$request instanceof Request) {
            return !$this->rowsRequest instanceof WeakReference;
        }

        return $this->rowsRequest?->get() === $request;
    }
}
