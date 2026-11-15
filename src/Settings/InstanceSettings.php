<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Settings;

use DateTimeImmutable;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Event\AfterCommitEventDispatcher;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\EventListener\WorkerBoundaryResetListener;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Exception\SettingsConflictException;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;
use Nowo\InstanceSettingsBundle\Expiry\ExpiryDate;
use Nowo\InstanceSettingsBundle\Expiry\LockingSecretExpiryStoreInterface;
use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryRecord;
use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryStoreInterface;
use Nowo\InstanceSettingsBundle\File\FileStoreTransaction;
use Nowo\InstanceSettingsBundle\File\ImageProcessor;
use Nowo\InstanceSettingsBundle\File\ImageRefusedException;
use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\File\ProcessedImage;
use Nowo\InstanceSettingsBundle\File\SettingFileStoreInterface;
use Nowo\InstanceSettingsBundle\File\SettingFileUrls;
use Nowo\InstanceSettingsBundle\File\StoredSettingFile;
use Nowo\InstanceSettingsBundle\Repository\SectionLock;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Repository\StoredSecretStateInterface;
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
use function array_keys;
use function array_map;
use function hash_hmac;
use function implode;
use function in_array;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function preg_match;
use function sprintf;
use function substr;
use function trim;

use const JSON_PRESERVE_ZERO_FRACTION;

/**
 * Default {@see InstanceSettingsInterface}: one query per request for every row, memoized for the current main request
 * only (a new main request always reloads, even when `kernel.reset` is not called between worker requests) and cleared
 * by {@see reset()} and after every save. Outside HTTP (Messenger message, console command) the memo lives for one unit
 * of work: {@see WorkerBoundaryResetListener} calls {@see reset()} at every boundary, also when the services resetter
 * does not run.
 */
final class InstanceSettings implements InstanceSettingsInterface, SnapshotSettingsWriterInterface, ResetInterface
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
     * @param SecretExpiryStoreInterface|null $expiryStore expiry dates of the expiring secrets (1.17; required to save a
     *                                                     section that declares one)
     * @param string $snapshotKey keys the fingerprints of {@see self::snapshot()} (1.19: `%kernel.secret%`)
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
        private readonly ?SecretExpiryStoreInterface $expiryStore = null,
        private readonly string $snapshotKey = '',
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
     * Validates every value first, then upserts / removes the changed rows in one unit of work, under the section's write
     * lock (1.19): the stored rows are read again under the lock, so a concurrent save is never undone by a stale read.
     */
    public function save(string $section, array $values, string $origin = SettingsChangedEvent::ORIGIN_API): array
    {
        return $this->write($section, $values, $origin, null);
    }

    /**
     * The section as stored now, as its form shows it (1.19).
     */
    public function snapshot(string $section): ?SectionSnapshot
    {
        $fields = $this->registry->fields($section);
        if ($this->snapshotKey === '') {
            // Audit QQ-L-1: no token without a key (an unkeyed fingerprint could be recomputed by anyone).
            return null;
        }
        $dates = $this->hasExpiringFields($fields) && $this->expiryStore instanceof SecretExpiryStoreInterface ? $this->expiryStore->findBySection($section) : [];

        return new SectionSnapshot($section, $this->fingerprints($section, $fields, $this->rows()[$section] ?? [], $dates));
    }

    /**
     * Saves a section form merged with the changes saved since `$base` (1.19, {@see SnapshotSettingsWriterInterface}).
     */
    public function saveSince(string $section, array $values, SectionSnapshot $base, string $origin = SettingsChangedEvent::ORIGIN_FORM): array
    {
        return $this->write($section, $values, $origin, $base);
    }

    /**
     * A save: the values are normalized first (images re-encoded), outside any lock; then, in one unit of work under the
     * section's write lock, the stored rows are read again, the base snapshot (if any) is merged, the required rules are
     * checked against the stored state and the changes are written. The log line and the event follow the commit.
     *
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    private function write(string $section, array $values, string $origin, ?SectionSnapshot $base): array
    {
        $fields = $this->registry->fields($section);
        // 1.17: the companion expiry dates (`<field>:expires_on`) of expiring secrets, taken out before anything else.
        [$values, $expiryInput, $expiryViolations] = $this->extractExpiryDates($section, $values);
        $definition                                = $this->registry->get($section);
        if ($definition instanceof SettingsSectionSaveFilterInterface) {
            // 1.5: the section may rewrite its values (lower-case colours, drop a value equal to its runtime default).
            $values = $definition->filterValues($values, $this->getSection($section));
        }
        $normalized  = [];
        $imageValues = [];
        $violations  = $expiryViolations;
        $required    = [];
        // Pass 1: normalize every value; `required` is decided in pass 2, under the lock, against the stored values.
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
        if ($violations !== []) {
            throw new InvalidSettingValueException($section, $violations);
        }

        $store = $imageValues === [] ? null : $this->fileStore;

        try {
            // @igor-ignore - The closure runs synchronously inside the unit of work and is not stored by the repository.
            $written = FileStoreTransaction::run($this->repository, $store, fn (): ?array => $this->writeLocked($section, $fields, $values, $normalized, $imageValues, $required, $expiryInput, $base));
        } finally {
            // The memo may hold rows changed in memory by the unit (written or rolled back): read again next time.
            $this->reset();
        }
        if ($written === null) {
            return [];
        }
        [$changed, $secrets, $fileChanges, $newImages, $expiryChanges] = $written;

        $saved   = [...$changed, ...array_map(SettingField::expiryKey(...), array_keys($expiryChanges))];
        $context = [
            'bundle'  => 'NowoInstanceSettingsBundle',
            'section' => $section,
            'fields'  => implode(',', $saved),
            'origin'  => $origin,
        ];
        if ($fileChanges !== []) {
            $context['files'] = $this->describeFiles($fileChanges, $newImages);
        }
        // 1.19: the audit line follows the outermost commit (dropped with a rolled-back host transaction), like the event.
        $logger = $this->logger;
        AfterCommitEventDispatcher::afterCommitOf($this->dispatcher, static function () use ($logger, $context): void {
            $logger?->info('Instance settings saved.', $context);
        });
        if ($changed !== []) {
            // A date-only change is not a settings change: the event keeps listing field names only (1.17).
            $this->dispatcher?->dispatch(new SettingsChangedEvent($section, $changed, $secrets, $origin, $fileChanges));
        }

        return $saved;
    }

    /**
     * The locked part of a save, inside the unit of work: null when nothing changed, else the changed fields, the changed
     * secrets, the file changes, the new images and the expiry date changes.
     *
     * @param array<string, SettingField> $fields
     * @param array<array-key, mixed> $values submitted values (after the section filter)
     * @param array<string, bool|float|int|string|null> $normalized normalized plain and secret values
     * @param array<string, ProcessedImage|string|null> $imageValues normalized image values
     * @param array<string, mixed> $required submitted values that normalized to null (the `required` rule)
     * @param array<string, DateTimeImmutable|null> $expiryInput dates given with the save
     *
     * @throws InvalidSettingValueException
     * @throws SettingsConflictException
     *
     * @return array{0: list<string>, 1: list<string>, 2: array<string, array{old: string|null, new: string|null}>, 3: array<string, ProcessedImage|null>, 4: array<string, DateTimeImmutable|null>}|null
     */
    private function writeLocked(string $section, array $fields, array $values, array $normalized, array $imageValues, array $required, array $expiryInput, ?SectionSnapshot $base): ?array
    {
        $existing = [];
        foreach (SectionLock::rowsOf($this->repository, $section) as $row) {
            $existing[$row->getName()] = $row;
        }
        // Audit QQ-D-L-1: the write time is taken once the lock is held, so `updated_at` follows the commit order.
        $now     = $this->clock?->now() ?? (new NativeClock())->now();
        $current = $this->effectiveValues($fields, $existing);
        if ($base instanceof SectionSnapshot) {
            [$normalized, $imageValues, $expiryInput, $required] = $this->merge($section, $fields, $values, $normalized, $imageValues, $expiryInput, $required, $base, $existing);
        }

        // Pass 2: `required` only applies to a provider-dependent field visible for the normalized saved values merged
        // over the effective stored ones (1.3); a value saved empty takes its default, as it will once its row is
        // deleted (1.3.1).
        $context    = null;
        $violations = [];
        foreach ($required as $name => $value) {
            $field   = $fields[$name];
            $visible = true;
            if ($field->visibleWhen !== null) {
                $context ??= $this->visibilityContext($fields, $normalized, $current);
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
        // 1.12: a colour equal to its preset (default, or the colour of the submitted / stored preset source value) is
        // not stored, so a later change of the preset still reaches it. A colour re-submitted unchanged (the stored
        // value) is kept as is (audit LM-M1: an untouched save never changes a stored colour).
        $presetContext = null;
        foreach ($normalized as $name => $value) {
            if ($value !== null && $fields[$name]->type === SettingType::Color && ($existing[$name] ?? null)?->getValue() !== $value) {
                $presetContext ??= $this->visibilityContext($fields, $normalized, $current);
                if ($value === $fields[$name]->presetColor($presetContext)) {
                    $normalized[$name] = null;
                }
            }
        }

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

        // 1.17: the expiry dates follow their secrets (a new secret clears the date unless one is given with it).
        $expiryChanges = $expiryInput === [] && !$this->changesExpiringSecret($fields, $changed) ? [] : $this->expiryChanges($section, $fields, $expiryInput, $normalized, $existing, $changed);

        if ($changed === [] && $expiryChanges === []) {
            return null;
        }
        $this->applyChanges($section, $save, $remove, $newImages, $orphans, $now, $expiryChanges);

        return [$changed, $secrets, $fileChanges, $newImages, $expiryChanges];
    }

    /**
     * Merges a section form with the changes saved since it was opened (1.19): a field another editor changed meanwhile
     * is dropped from the save when this editor left it as shown, and is a conflict when this editor changed it too (to
     * another value) or acted on a secret, an image or an expiry date replaced meanwhile.
     *
     * @param array<string, SettingField> $fields
     * @param array<array-key, mixed> $values
     * @param array<string, bool|float|int|string|null> $normalized
     * @param array<string, ProcessedImage|string|null> $imageValues
     * @param array<string, DateTimeImmutable|null> $expiryInput
     * @param array<string, mixed> $required
     * @param array<string, SettingValue> $existing
     *
     * @throws SettingsConflictException
     *
     * @return array{0: array<string, bool|float|int|string|null>, 1: array<string, ProcessedImage|string|null>, 2: array<string, DateTimeImmutable|null>, 3: array<string, mixed>}
     */
    private function merge(string $section, array $fields, array $values, array $normalized, array $imageValues, array $expiryInput, array $required, SectionSnapshot $base, array $existing): array
    {
        $dates     = $expiryInput !== [] && $this->expiryStore instanceof SecretExpiryStoreInterface ? $this->lockedDates($this->expiryStore, $section) : [];
        $now       = $this->fingerprints($section, $fields, $existing, $dates, true);
        $conflicts = [];
        foreach ($normalized as $name => $value) {
            $was = $base->fingerprint($name);
            if ($was === null || $was === $now[$name]) {
                continue;
            }
            if (!$fields[$name]->secret) {
                if ($this->fingerprint($section, $fields[$name], $values[$name] ?? null) === $was || $this->fingerprint($section, $fields[$name], $value) === $was) {
                    // Left as shown: the other editor's value stays.
                    unset($normalized[$name], $required[$name]);

                    continue;
                }
                if ($this->fingerprint($section, $fields[$name], $value) === $now[$name]) {
                    continue;
                }
            }
            $conflicts[] = $name;
        }
        foreach ($imageValues as $name => $value) {
            $was = $base->fingerprint($name);
            if ($was === null || $was === $now[$name]) {
                continue;
            }
            if (is_string($value)) {
                unset($imageValues[$name]);

                continue;
            }
            $conflicts[] = $name;
        }
        foreach ($expiryInput as $name => $date) {
            $key = SettingField::expiryKey($name);
            $was = $base->fingerprint($key);
            if ($was === null) {
                continue;
            }
            $dateMoved   = $was !== ($now[$key] ?? null);
            $secretMoved = ($base->fingerprint($name) ?? $now[$name]) !== $now[$name];
            if ($dateMoved && $this->hash($section, $key, $date === null ? '' : ExpiryDate::format($date)) === $was) {
                // Audit QQ-L-3: the date was left as shown and changed meanwhile (a rotation cleared it): theirs stays.
                unset($expiryInput[$name]);

                continue;
            }
            if ($dateMoved || $secretMoved) {
                // Audit QQ-M-1: a date typed for the secret shown never lands on a secret replaced meanwhile.
                $conflicts[] = $key;
            }
        }
        if ($conflicts !== []) {
            throw new SettingsConflictException($section, $conflicts);
        }

        return [$normalized, $imageValues, $expiryInput, $required];
    }

    /**
     * Fingerprints of a section as its form shows it (1.19): every field, and the expiry date of every expiring secret.
     *
     * @param array<string, SettingField> $fields
     * @param array<string, SettingValue> $rows stored rows by field name
     * @param array<string, SecretExpiryRecord> $dates stored expiry dates by field name
     * @param bool $locked inside the unit, under the section lock: the stored secrets are read with a locking read
     *
     * @return array<string, string>
     */
    private function fingerprints(string $section, array $fields, array $rows, array $dates, bool $locked = false): array
    {
        $fingerprints = [];
        // Audit QQ-D-M-1: under the lock, the stored secrets are read with a locking read (the latest committed ones, also
        // inside a REPEATABLE READ transaction of the host that read the table before).
        $states = $this->repository instanceof StoredSecretStateInterface ? $this->repository->storedSecretStates($section, $locked) : [];
        foreach ($fields as $name => $field) {
            $row = $rows[$name] ?? null;
            if ($field->secret) {
                $fingerprints[$name] = $this->hash($section, $name, $this->secretState($row, $states[$name] ?? null));
                if ($field->expires) {
                    $date                                         = $dates[$name] ?? null;
                    $fingerprints[SettingField::expiryKey($name)] = $this->hash($section, SettingField::expiryKey($name), $date === null ? '' : ExpiryDate::format($date->expiresOn));
                }

                continue;
            }
            if ($field->type === SettingType::Image) {
                $fingerprints[$name] = $this->hash($section, $name, (string) json_encode($row?->getValue()));

                continue;
            }
            // What the form shows: the effective value; an unstored colour is empty (= follow the preset, 1.12).
            $shown               = $field->type === SettingType::Color && $row === null ? null : $this->effectiveFrom($field, $row);
            $fingerprints[$name] = $this->fingerprint($section, $field, $shown);
        }

        return $fingerprints;
    }

    /**
     * What a secret's fingerprint is made of (audit QQ-L-1): never its plaintext. The stored (encrypted) form when the
     * repository gives it and it differs from the plaintext (a new ciphertext on every write), else the row's id and
     * time; '' without a row.
     */
    private function secretState(?SettingValue $row, ?string $stored): string
    {
        if (!$row instanceof SettingValue) {
            return '';
        }
        if ($stored !== null && $stored !== $row->getSecretValue()) {
            return 'c:' . $stored;
        }

        return 'r:' . $row->getId() . ':' . $row->getUpdatedAt()->format('U.u');
    }

    /**
     * Fingerprint of a plain value as a form shows it (numbers of a float field compare as floats).
     */
    private function fingerprint(string $section, SettingField $field, mixed $value): string
    {
        if ($field->type === SettingType::Float && is_int($value)) {
            $value = (float) $value;
        }

        return $this->hash($section, $field->name, (string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Keyed hash of one field state (never a value in the clear, never an unkeyed hash of a secret).
     */
    private function hash(string $section, string $key, string $state): string
    {
        return substr(hash_hmac('sha256', $section . "\0" . $key . "\0" . $state, 'nowo_instance_settings_snapshot' . "\0" . $this->snapshotKey), 0, 16);
    }

    /**
     * Whether a section declares an expiring secret.
     *
     * @param array<string, SettingField> $fields
     */
    private function hasExpiringFields(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field->expires) {
                return true;
            }
        }

        return false;
    }

    /**
     * Takes the companion expiry dates (`<field>:expires_on`, 1.17) out of the saved values: the remaining values, the
     * parsed dates (field => date or null) and the violations of the malformed ones (keyed by the companion key).
     *
     * @param array<array-key, mixed> $values
     *
     * @throws UnknownSettingException for the companion key of a field that does not declare `expires`
     *
     * @return array{0: array<array-key, mixed>, 1: array<string, DateTimeImmutable|null>, 2: array<string, list<string>>}
     */
    private function extractExpiryDates(string $section, array $values): array
    {
        $dates      = [];
        $violations = [];
        foreach ($values as $key => $value) {
            $name = SettingField::expiryField((string) $key);
            if ($name === null) {
                continue;
            }
            if (!$this->registry->field($section, $name)->expires) {
                throw UnknownSettingException::field($section, (string) $key);
            }
            unset($values[$key]);
            $date = ExpiryDate::parse($value);
            if ($date === false) {
                $violations[(string) $key] = ['This value is not a valid date.'];

                continue;
            }
            $dates[$name] = $date;
        }

        return [$values, $dates, $violations];
    }

    /**
     * Whether a changed field of the save is an expiring secret (its date may have to be cleared).
     *
     * @param array<string, SettingField> $fields
     * @param list<string> $changed
     */
    private function changesExpiringSecret(array $fields, array $changed): bool
    {
        foreach ($changed as $name) {
            if ($fields[$name]->expires) {
                return true;
            }
        }

        return false;
    }

    /**
     * The expiry date writes of a save (1.17, field => date or null = remove), by the documented rule:
     *
     * - a **new secret** is saved: its date is cleared, or set to the date given in the same save;
     * - the secret is **removed**: its date is removed (a date given with it is ignored);
     * - the secret is **kept** and a date is given: the date is set (null removes it) — only while a secret is stored.
     *
     * A write equal to the stored date is dropped.
     *
     * @param array<string, SettingField> $fields
     * @param array<string, DateTimeImmutable|null> $input dates given with the save
     * @param array<string, bool|float|int|string|null> $normalized normalized saved values
     * @param array<string, SettingValue> $existing stored rows of the section
     * @param list<string> $changed changed field names
     *
     * @throws InvalidSettingValueException for a date given for a field without a stored or saved secret
     *
     * @return array<string, DateTimeImmutable|null>
     */
    private function expiryChanges(string $section, array $fields, array $input, array $normalized, array $existing, array $changed): array
    {
        $store      = $this->expiryStore ?? throw new LogicException(sprintf('Instance settings section "%s" declares an expiring secret: the store needs a SecretExpiryStoreInterface (nowo_instance_settings.expiring_secrets.enabled: true).', $section));
        $stored     = $this->lockedDates($store, $section);
        $changes    = [];
        $violations = [];
        foreach ($fields as $name => $field) {
            if (!$field->expires) {
                continue;
            }
            $given = array_key_exists($name, $input);
            if (in_array($name, $changed, true)) {
                // New secret: the given date, else none. Removed secret: none.
                $target = ($normalized[$name] ?? null) !== null && $given ? $input[$name] : null;
            } elseif ($given) {
                if ($input[$name] !== null && ($existing[$name] ?? null)?->getSecretValue() === null) {
                    $violations[SettingField::expiryKey($name)] = ['Save the secret before its expiry date.'];

                    continue;
                }
                $target = $input[$name];
            } else {
                continue;
            }
            $current = $stored[$name] ?? null;
            if (($current === null ? null : ExpiryDate::format($current->expiresOn)) !== ($target === null ? null : ExpiryDate::format($target))) {
                $changes[$name] = $target;
            }
        }
        if ($violations !== []) {
            throw new InvalidSettingValueException($section, $violations);
        }

        return $changes;
    }

    /**
     * The expiry dates of a section inside the unit of work, under the section lock: a locking read when the store supports
     * it (1.19.1, audit QQ-D2-L-1: the dates as last committed, also inside a REPEATABLE READ transaction of the host).
     *
     * @return array<string, SecretExpiryRecord>
     */
    private function lockedDates(SecretExpiryStoreInterface $store, string $section): array
    {
        return $store instanceof LockingSecretExpiryStoreInterface ? $store->findBySectionForUpdate($section) : $store->findBySection($section);
    }

    /**
     * Effective values after a save: the normalized saved values (an empty one replaced by its field default) merged
     * over the effective stored ones.
     *
     * @param array<string, SettingField> $fields
     * @param array<string, bool|float|int|string|null> $normalized
     * @param array<string, bool|float|int|string|null> $current effective stored values (read under the lock, 1.19)
     *
     * @return array<string, mixed>
     */
    private function visibilityContext(array $fields, array $normalized, array $current): array
    {
        foreach ($normalized as $name => $value) {
            if ($value === null) {
                $normalized[$name] = $fields[$name]->default;
            }
        }

        return $normalized + $current;
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
     * Inside the unit of work: writes the image files, orphans the replaced ones, applies the rows and writes the expiry
     * dates (1.17). A staged (filesystem) store applies its file changes only after the outermost commit (1.7, audit JG-M1).
     *
     * @param list<SettingValue> $save
     * @param list<SettingValue> $remove
     * @param array<string, ProcessedImage|null> $newImages
     * @param list<array{0: string, 1: string}> $orphans field => replaced hash
     * @param array<string, DateTimeImmutable|null> $expiryChanges field => expiry date or null (1.17)
     */
    private function applyChanges(string $section, array $save, array $remove, array $newImages, array $orphans, DateTimeImmutable $now, array $expiryChanges): void
    {
        $store = $newImages === [] && $orphans === [] ? $this->fileStore : ($this->fileStore ?? throw new LogicException('Instance settings image fields need a file store (SettingFileStoreInterface).'));
        foreach ($newImages as $name => $image) {
            if ($image instanceof ProcessedImage) {
                $store?->save($section, $name, $image, $now);
            }
        }
        foreach ($orphans as [$name, $sha]) {
            $store?->orphan($section, $name, $sha, $now);
        }
        if ($save !== [] || $remove !== []) {
            $this->repository->apply($save, $remove);
        }
        if ($expiryChanges !== []) {
            $this->expiryStore?->write($section, $expiryChanges, $now);
        }
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
        return $this->effectiveFrom($field, $this->rows()[$section][$name] ?? null);
    }

    /**
     * Effective value of a field for its stored row (or none).
     */
    private function effectiveFrom(SettingField $field, ?SettingValue $row): bool|float|int|string|null
    {
        if (!$row instanceof SettingValue) {
            return $field->default;
        }

        $value = $row->getValue();

        // A stored value that no longer matches the field (type changed in code) falls back to the default.
        return $value !== null && SettingField::matchesType($field->type, $value, $field->choices) ? $value : $field->default;
    }

    /**
     * Effective plain values of a section (no secrets, no image fields) for its stored rows.
     *
     * @param array<string, SettingField> $fields
     * @param array<string, SettingValue> $rows
     *
     * @return array<string, bool|float|int|string|null>
     */
    private function effectiveValues(array $fields, array $rows): array
    {
        $values = [];
        foreach ($fields as $name => $field) {
            if (!$field->secret && $field->type !== SettingType::Image) {
                $values[$name] = $this->effectiveFrom($field, $rows[$name] ?? null);
            }
        }

        return $values;
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
