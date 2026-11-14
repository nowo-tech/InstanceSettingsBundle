<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry;

use DateTimeImmutable;

/**
 * One expiring secret within a window (1.17), the value object of {@see ExpiringSecretSourceInterface}: where it is
 * (section, field), how to name it (label translation key and domain) and when it expires. It never carries the secret
 * value, nor whether it can be revealed.
 */
final readonly class ExpiringSecret
{
    /**
     * @param string $section section name
     * @param string $field field name inside the section
     * @param string $label translation key of the field label (in `$translationDomain`)
     * @param string $translationDomain translation domain of the section
     * @param DateTimeImmutable $expiresOn expiry date ({@see ExpiryDate}: 00:00 UTC, no time)
     * @param int $daysLeft whole days from the "today" of the query to the date: 0 on the day, negative once expired
     */
    public function __construct(
        public string $section,
        public string $field,
        public string $label,
        public string $translationDomain,
        public DateTimeImmutable $expiresOn,
        public int $daysLeft,
    ) {
    }

    /**
     * `section.field` key.
     */
    public function key(): string
    {
        return $this->section . '.' . $this->field;
    }

    /**
     * Whether the date has passed (`daysLeft` < 0); on the day itself the secret is not expired yet.
     */
    public function isExpired(): bool
    {
        return $this->daysLeft < 0;
    }

    /**
     * The date as `YYYY-MM-DD`.
     */
    public function expiresOnString(): string
    {
        return ExpiryDate::format($this->expiresOn);
    }
}
