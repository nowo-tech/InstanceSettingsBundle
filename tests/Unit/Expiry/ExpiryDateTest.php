<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Expiry;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\InstanceSettingsBundle\Expiry\ExpiryDate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function date_default_timezone_get;

use const DATE_ATOM;

/**
 * 1.17: calendar dates — strict parsing, "today" per zone and day boundaries, whole days across DST changes.
 */
final class ExpiryDateTest extends TestCase
{
    public function testStrictParsing(): void
    {
        self::assertSame('2027-01-31T00:00:00+00:00', ExpiryDate::fromStrict('2027-01-31')?->format(DATE_ATOM));
        self::assertNull(ExpiryDate::fromStrict('2027-02-30'), 'not a real day');
        self::assertNull(ExpiryDate::fromStrict('2027-1-31'));
        self::assertNull(ExpiryDate::fromStrict("2027-01-31\n"));

        self::assertNull(ExpiryDate::parse(null));
        self::assertNull(ExpiryDate::parse('  '));
        self::assertSame('2027-01-31', ExpiryDate::format(ExpiryDate::parse(' 2027-01-31 ') ?: new DateTimeImmutable()));
        self::assertFalse(ExpiryDate::parse('tomorrow'));
        self::assertFalse(ExpiryDate::parse(20270131));
        self::assertFalse(ExpiryDate::parse((new DateTimeImmutable('9999-12-31'))->modify('+1 day')), 'five-digit years are refused');
        $date = ExpiryDate::parse(new DateTimeImmutable('2027-01-31 23:59:59', new DateTimeZone('Pacific/Kiritimati')));
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2027-01-31T00:00:00+00:00', $date->format(DATE_ATOM), 'its own calendar date, as a UTC midnight');
    }

    public function testTodayFollowsTheConfiguredZoneAcrossTheDayBoundary(): void
    {
        // 23:30 UTC on 14 November: already the 15th in Madrid, still the 14th in New York.
        $clock = new MockClock(new DateTimeImmutable('2026-11-14 23:30:00', new DateTimeZone('UTC')));

        self::assertSame('2026-11-14', ExpiryDate::format(ExpiryDate::today($clock, 'UTC')));
        self::assertSame('2026-11-15', ExpiryDate::format(ExpiryDate::today($clock, 'Europe/Madrid')));
        self::assertSame('2026-11-14', ExpiryDate::format(ExpiryDate::today($clock, 'America/New_York')));

        self::assertEquals(ExpiryDate::today($clock, date_default_timezone_get()), ExpiryDate::today($clock), 'null = the PHP default zone');
    }

    public function testDaysBetweenAreWholeDaysWhateverTheDaylightSavingRules(): void
    {
        $madrid = new DateTimeZone('Europe/Madrid');
        // 2027-03-28: the clocks go forward in Madrid (a 23-hour day); 2027-10-31: back (a 25-hour day).
        self::assertSame(1, ExpiryDate::daysBetween(new DateTimeImmutable('2027-03-27 23:59', $madrid), new DateTimeImmutable('2027-03-28 00:00', $madrid)));
        self::assertSame(2, ExpiryDate::daysBetween(new DateTimeImmutable('2027-03-27', $madrid), new DateTimeImmutable('2027-03-29', $madrid)));
        self::assertSame(0, ExpiryDate::daysBetween(new DateTimeImmutable('2027-10-31 00:01', $madrid), new DateTimeImmutable('2027-10-31 23:59', $madrid)));
        self::assertSame(-3, ExpiryDate::daysBetween(new DateTimeImmutable('2027-11-03', $madrid), new DateTimeImmutable('2027-10-31', $madrid)));
        self::assertSame(365, ExpiryDate::daysBetween(ExpiryDate::of(new DateTimeImmutable('2026-01-01', $madrid)), ExpiryDate::of(new DateTimeImmutable('2027-01-01', $madrid))));
    }
}
