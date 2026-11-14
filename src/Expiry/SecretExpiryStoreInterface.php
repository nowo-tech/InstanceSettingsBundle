<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;

/**
 * Storage of the expiry dates of expiring secrets (1.17): the bundle's own store, framework-free (dates and
 * {@see SecretExpiryRecord}s only). The default implementation is {@see DoctrineSecretExpiryStore}.
 *
 * Application code changes a date through {@see InstanceSettingsInterface::save()} (it applies the
 * "new secret clears the date" rule) and reads dates through {@see ExpiringSecretSourceInterface}.
 */
interface SecretExpiryStoreInterface
{
    /**
     * Every stored date, ordered by section and field.
     *
     * @return list<SecretExpiryRecord>
     */
    public function findAll(): array;

    /**
     * Stored dates of one section, keyed by field name.
     *
     * @return array<string, SecretExpiryRecord>
     */
    public function findBySection(string $section): array;

    /**
     * Sets or removes dates of one section: a date inserts or replaces the field's date (a different date forgets the
     * thresholds announced for the old one), null removes it. A date equal to the stored one changes nothing.
     *
     * @param array<string, DateTimeImmutable|null> $dates field name => date or null
     */
    public function write(string $section, array $dates, DateTimeImmutable $at): void;

    /**
     * Records `$threshold` as announced for the field's date, atomically: true only for the one caller that moved the
     * record (the date is still `$expiresOn` and no equal or smaller threshold was announced for it), so two concurrent
     * runs never announce the same threshold twice.
     */
    public function claim(string $section, string $field, DateTimeImmutable $expiresOn, int $threshold, DateTimeImmutable $at): bool;

    /**
     * Undoes a {@see self::claim()} whose announcement failed (back to `$previous`), so the next run retries it. Does
     * nothing when the record moved meanwhile (another date or threshold).
     */
    public function release(string $section, string $field, DateTimeImmutable $expiresOn, int $threshold, ?int $previous): void;
}
