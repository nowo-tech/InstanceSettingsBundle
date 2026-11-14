<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Clock\ClockInterface;

use function date_default_timezone_get;
use function intdiv;
use function is_string;
use function preg_match;
use function trim;

/**
 * Calendar dates of the expiring secrets (1.17): a date has no time and no zone.
 *
 * Every date is held as a {@see DateTimeImmutable} at 00:00:00 UTC, so the number of days between two dates is an exact
 * multiple of 86 400 seconds whatever the daylight saving rules of the host. "Today" is the calendar date of the clock
 * in the configured zone (`expiring_secrets.timezone`, default the PHP default zone), taken once per call: a check
 * running at 23:59 in Madrid and one at 00:01 the next day see two different days, whatever the server zone is.
 */
final class ExpiryDate
{
    /** Storage, form and event format of a date. */
    public const FORMAT = 'Y-m-d';

    /** Strict `YYYY-MM-DD`. */
    private const PATTERN = '/^\d{4}-\d{2}-\d{2}$/D';

    private const SECONDS_PER_DAY = 86_400;

    /**
     * The calendar date of a date-time **in its own zone** (a form date, a host value), as a date.
     */
    public static function of(DateTimeInterface $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value->format(self::FORMAT) . 'T00:00:00', new DateTimeZone('UTC'));
    }

    /**
     * A strict `YYYY-MM-DD` string as a date; null when malformed or not a real day (2027-02-30).
     */
    public static function fromStrict(string $value): ?DateTimeImmutable
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, new DateTimeZone('UTC'));

        return $date instanceof DateTimeImmutable && $date->format(self::FORMAT) === $value ? $date : null;
    }

    /**
     * A user / import value as a date: null or '' (no date), a {@see DateTimeInterface} or a strict `YYYY-MM-DD`
     * string. False for anything else.
     */
    public static function parse(mixed $value): DateTimeImmutable|false|null
    {
        if ($value instanceof DateTimeInterface) {
            // A date-time of a host / form: its own calendar date, kept only within the strict four-digit years.
            return self::fromStrict($value->format(self::FORMAT)) ?? false;
        }
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return is_string($value) ? self::fromStrict(trim($value)) ?? false : false;
    }

    /**
     * Today's calendar date for the clock, in `$timezone` (null = the PHP default zone).
     */
    public static function today(ClockInterface $clock, ?string $timezone = null): DateTimeImmutable
    {
        return self::of($clock->now()->setTimezone(new DateTimeZone($timezone ?? date_default_timezone_get())));
    }

    /**
     * Whole days from `$today` to `$expiresOn` (both dates): 0 on the day, negative once expired.
     */
    public static function daysBetween(DateTimeImmutable $today, DateTimeImmutable $expiresOn): int
    {
        return intdiv(self::of($expiresOn)->getTimestamp() - self::of($today)->getTimestamp(), self::SECONDS_PER_DAY);
    }

    /**
     * `YYYY-MM-DD` of a date.
     */
    public static function format(DateTimeImmutable $date): string
    {
        return $date->format(self::FORMAT);
    }
}
