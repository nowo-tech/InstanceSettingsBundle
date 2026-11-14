<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function usort;

/**
 * Default {@see ExpiringSecretSourceInterface} (1.17) over the registry, the expiry store and the settings store, plus
 * what the admin page needs: the stored dates of a section, today's date and the fields to warn about.
 *
 * A stored date counts only for a field that still declares `expires: true` and still holds a secret (a date left by a
 * removed field, or written around the bundle, is ignored). Stateless: "today" is read from the clock on every call, in
 * `expiring_secrets.timezone`; nothing is memoized, so a kept-alive worker never serves yesterday's dates.
 */
final readonly class ExpiringSecrets implements ExpiringSecretSourceInterface
{
    /** Default `expiring_secrets.warning_days`. */
    public const DEFAULT_WARNING_DAYS = 30;

    /**
     * @param ClockInterface|null $clock source of "today" (null = the system clock)
     * @param string|null $timezone zone of "today" (`expiring_secrets.timezone`; null = the PHP default zone)
     * @param int|null $warningDays the page warns from this many days before the date (`expiring_secrets.warning_days`);
     *                              null = no warning
     */
    public function __construct(
        private SettingsSectionRegistry $registry,
        private SecretExpiryStoreInterface $store,
        private InstanceSettingsInterface $settings,
        private ?ClockInterface $clock = null,
        private ?string $timezone = null,
        private ?int $warningDays = self::DEFAULT_WARNING_DAYS,
    ) {
    }

    /**
     * Expiring secrets due within `$days` days of `$today`, soonest first.
     */
    public function expiringWithin(int $days, DateTimeImmutable $today): array
    {
        $today = ExpiryDate::of($today);
        $due   = [];
        foreach ($this->store->findAll() as $record) {
            $secret = $this->describe($record, $today);
            if ($secret instanceof ExpiringSecret && $secret->daysLeft <= $days) {
                $due[] = $secret;
            }
        }
        usort($due, static fn (ExpiringSecret $a, ExpiringSecret $b): int => [$a->daysLeft, $a->key()] <=> [$b->daysLeft, $b->key()]);

        return $due;
    }

    /**
     * Today's date ({@see ExpiryDate::today()}) for the configured clock and zone.
     */
    public function today(): DateTimeImmutable
    {
        return ExpiryDate::today($this->clock ?? new NativeClock(), $this->timezone);
    }

    /**
     * Stored dates of the expiring fields of a section (field name => date), whether or not a warning is due.
     *
     * @return array<string, DateTimeImmutable>
     */
    public function datesOf(string $section): array
    {
        $fields = $this->registry->fields($section);
        $dates  = [];
        foreach ($this->store->findBySection($section) as $name => $record) {
            if (($fields[$name] ?? null)?->expires === true) {
                $dates[$name] = $record->expiresOn;
            }
        }

        return $dates;
    }

    /**
     * Fields of a section to warn about on its page (field name => secret): within `warning_days` of their date, or
     * expired. Empty when the warning is off.
     *
     * @return array<string, ExpiringSecret>
     */
    public function warnings(string $section): array
    {
        if ($this->warningDays === null) {
            return [];
        }
        $warnings = [];
        foreach ($this->expiringWithin($this->warningDays, $this->today()) as $secret) {
            if ($secret->section === $section) {
                $warnings[$secret->field] = $secret;
            }
        }

        return $warnings;
    }

    /**
     * The read-port view of a stored date, or null when it does not count (unknown section or field, a field without
     * `expires`, no stored secret).
     *
     * @internal used by {@see SecretExpiryChecker}
     */
    public function describe(SecretExpiryRecord $record, DateTimeImmutable $today): ?ExpiringSecret
    {
        if (!$this->registry->has($record->section)) {
            return null;
        }
        $definition = $this->registry->get($record->section);
        $field      = $this->registry->fields($record->section)[$record->field] ?? null;
        if ($field === null || !$field->expires || !$this->settings->hasSecret($record->section . '.' . $record->field)) {
            return null;
        }

        return new ExpiringSecret(
            $record->section,
            $record->field,
            $field->labelKey(),
            $definition->getTranslationDomain(),
            $record->expiresOn,
            ExpiryDate::daysBetween($today, $record->expiresOn),
        );
    }
}
