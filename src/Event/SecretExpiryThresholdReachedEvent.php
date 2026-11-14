<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Event;

use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryChecker;

/**
 * An expiring secret reached one of the `expiring_secrets.thresholds` (1.17), dispatched (PSR-14) by
 * {@see SecretExpiryChecker} / `nowo:instance-settings:check-expiring-secrets` **once per threshold per expiry date**,
 * across restarts and workers (the last announced threshold is stored next to the date).
 *
 * Scalars only, and never the secret: where it is, how to name it, when it expires and which threshold was crossed.
 * The host turns it into its own reminders (Symfony Beacon: a bell notification and an e-mail). When several thresholds
 * are crossed at once (the first run, or a job that did not run for a while) one event is dispatched, for the smallest
 * one; the larger ones count as announced. A listener that throws makes the run retry that threshold next time.
 */
final readonly class SecretExpiryThresholdReachedEvent
{
    /**
     * @param string $section section name
     * @param string $field field name inside the section
     * @param string $label translation key of the field label (in `$translationDomain`); translate and escape it
     * @param string $translationDomain translation domain of the section
     * @param string $expiresOn expiry date, `YYYY-MM-DD`
     * @param int $threshold the threshold (days before the date) that was reached; 0 = the day itself or later
     * @param int $daysLeft whole days left on the day of the run: 0 on the day, negative once expired
     */
    public function __construct(
        public string $section,
        public string $field,
        public string $label,
        public string $translationDomain,
        public string $expiresOn,
        public int $threshold,
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
     * Whether the date has passed on the day of the run.
     */
    public function isExpired(): bool
    {
        return $this->daysLeft < 0;
    }
}
