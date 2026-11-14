<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry;

use DateTimeImmutable;

/**
 * One stored expiry date (1.17), the value object of {@see SecretExpiryStoreInterface}: section, field name, date and
 * the last threshold announced for that date. It never carries the secret.
 */
final readonly class SecretExpiryRecord
{
    /**
     * @param string $section section name
     * @param string $field field name inside the section
     * @param DateTimeImmutable $expiresOn expiry date ({@see ExpiryDate})
     * @param int|null $notifiedThreshold last threshold (days) announced for this date; null = none yet
     */
    public function __construct(
        public string $section,
        public string $field,
        public DateTimeImmutable $expiresOn,
        public ?int $notifiedThreshold = null,
    ) {
    }
}
