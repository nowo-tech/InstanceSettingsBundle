<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\SectionTest;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestStateRow;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestStore;
use Nowo\InstanceSettingsBundle\SectionTest\StoredTestReport;
use Nowo\InstanceSettingsBundle\SectionTest\TestCheck;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySectionTestStateStorage;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function hash;
use function is_int;
use function strlen;

/**
 * 1.18: the last reports, the running markers and the built-in rate windows live in the settings table under reserved
 * section names; row names are hashes. Audit PU-L2 / L3: every write that decides something is atomic.
 */
final class SectionTestStoreTest extends TestCase
{
    private const ACTOR = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    public function testLastReportsAreKeptPerSectionAndGroup(): void
    {
        $storage = new InMemorySectionTestStateStorage();
        $store   = new SectionTestStore($storage);
        $at      = new DateTimeImmutable('2026-11-14 10:00:00');

        $store->saveReport(StoredTestReport::of('calendar', null, TestReport::of(TestCheck::ok('a.b')), $at, 'admin'));
        $store->saveReport(StoredTestReport::of('calendar', 'google', TestReport::of(TestCheck::fail('a.b')), $at, 'admin'));
        $store->saveReport(StoredTestReport::of('mailer', null, TestReport::of(TestCheck::ok('a.b')), $at, 'admin'));
        $store->saveReport(StoredTestReport::of('calendar', 'google', TestReport::of(TestCheck::skipped('a.b')), $at->modify('+1 minute'), 'super'));

        $reports = $store->lastReports('calendar');
        self::assertSame(['', 'google'], array_keys($reports));
        self::assertSame('super', $reports['google']->actor, 'replaced, not added');
        self::assertCount(3, $storage->rows);
        foreach ($storage->rows as $row) {
            self::assertSame(SectionTestStore::REPORTS, $row->section);
            self::assertSame(64, strlen($row->name), 'a hash fits the name column');
        }
        self::assertSame([], $store->lastReports('nope'));
    }

    public function testDamagedOrMisplacedReportsAreIgnoredAndALostRaceKeepsTheOtherReport(): void
    {
        $at      = new DateTimeImmutable();
        $storage = new InMemorySectionTestStateStorage();
        $storage->insert(SectionTestStore::REPORTS, SectionTestStore::reportName('calendar', 'other'), StoredTestReport::of('calendar', 'google', new TestReport(), $at, null)->toJson(), $at);
        $storage->insert(SectionTestStore::REPORTS, SectionTestStore::reportName('calendar', null), '{', $at);
        self::assertSame([], (new SectionTestStore($storage))->lastReports('calendar'));

        $racing = new InMemorySectionTestStateStorage([SectionTestStore::REPORTS]);
        (new SectionTestStore($racing))->saveReport(StoredTestReport::of('calendar', null, new TestReport(), $at, null));
        self::assertSame([], $racing->rows);
    }

    public function testOneRunningMarkerPerActorAndSection(): void
    {
        $storage = new InMemorySectionTestStateStorage();
        $store   = new SectionTestStore($storage);
        $now     = new DateTimeImmutable('2026-11-14 10:00:00');

        $first = $store->acquire(self::ACTOR, 'calendar', $now, 120);
        self::assertInstanceOf(SectionTestStateRow::class, $first);
        self::assertSame(32, strlen((string) $first->value), 'a per-request token');
        self::assertNull($store->acquire(self::ACTOR, 'calendar', $now->modify('+119 seconds'), 120), 'still running');
        self::assertInstanceOf(SectionTestStateRow::class, $store->acquire(self::ACTOR, 'mailer', $now, 120), 'another section');
        self::assertInstanceOf(SectionTestStateRow::class, $store->acquire(hash('sha256', 'other'), 'calendar', $now, 120), 'another actor');

        $store->release($first);
        self::assertCount(2, $storage->rows);
        $again = $store->acquire(self::ACTOR, 'calendar', $now, 120);
        self::assertInstanceOf(SectionTestStateRow::class, $again);
        self::assertNotSame($first->value, $again->value, 'a new token per request');
    }

    public function testAStaleMarkerIsTakenOverOnceAndOnlyItsOwnerReleasesIt(): void
    {
        // Audit PU-L3: A runs past the stale limit; B and C race for the takeover; A then finishes.
        $storage = new InMemorySectionTestStateStorage();
        $store   = new SectionTestStore($storage);
        $t0      = new DateTimeImmutable('2026-11-14 10:00:00');
        $a       = $store->acquire(self::ACTOR, 'mailer', $t0, 120);
        self::assertInstanceOf(SectionTestStateRow::class, $a);

        $later = $t0->modify('+121 seconds');
        $storage->freeze();
        $b = $store->acquire(self::ACTOR, 'mailer', $later, 120);
        $c = $store->acquire(self::ACTOR, 'mailer', $later, 120);
        $storage->thaw();
        self::assertInstanceOf(SectionTestStateRow::class, $b);
        self::assertNull($c, 'only one request takes a stale marker over');

        $store->release($a);
        self::assertNull($store->acquire(self::ACTOR, 'mailer', $later->modify('+1 second'), 120), 'A never removes B\'s marker');
        $store->release($b);
        self::assertInstanceOf(SectionTestStateRow::class, $store->acquire(self::ACTOR, 'mailer', $later->modify('+2 seconds'), 120));
    }

    public function testAMarkerThatAReaderCannotSeeYetMeansBusy(): void
    {
        // The insert lost against a marker this request's read does not show yet: busy, never a second marker.
        $storage = new InMemorySectionTestStateStorage();
        $store   = new SectionTestStore($storage);
        $now     = new DateTimeImmutable('2026-11-14 10:00:00');
        $storage->freeze();
        self::assertInstanceOf(SectionTestStateRow::class, $store->acquire(self::ACTOR, 'mailer', $now, 120));
        self::assertNull($store->acquire(self::ACTOR, 'mailer', $now, 120));
        $storage->thaw();
        self::assertCount(1, $storage->rows);
    }

    public function testTheBuiltInWindowCountsEveryConcurrentRequest(): void
    {
        // Audit PU-L2: N requests that all read before any of them wrote.
        $storage = new InMemorySectionTestStateStorage();
        $store   = new SectionTestStore($storage);
        $now     = new DateTimeImmutable('2026-11-14 10:00:00');
        for ($i = 0; $i < 9; ++$i) {
            self::assertNull($store->consume(self::ACTOR, 10, 600, $now));
        }
        $storage->freeze();
        $accepted = 0;
        for ($i = 0; $i < 8; ++$i) {
            $accepted += is_int($store->consume(self::ACTOR, 10, 600, $now)) ? 0 : 1;
        }
        $storage->thaw();
        self::assertSame(1, $accepted, 'the tenth test only');

        $empty = new InMemorySectionTestStateStorage();
        $other = new SectionTestStore($empty);
        $empty->freeze();
        $accepted = 0;
        for ($i = 0; $i < 25; ++$i) {
            $accepted += is_int($other->consume(self::ACTOR, 10, 600, $now)) ? 0 : 1;
        }
        $empty->thaw();
        self::assertSame(10, $accepted, 'a lost insert is never counted as accepted');
    }

    public function testTheBuiltInFixedWindowIsPerActorAndPrunesEndedWindows(): void
    {
        $storage = new InMemorySectionTestStateStorage();
        $store   = new SectionTestStore($storage);
        $now     = new DateTimeImmutable('@' . (600 * 3_000_000 + 10));

        self::assertNull($store->consume(self::ACTOR, 2, 600, $now));
        self::assertNull($store->consume(self::ACTOR, 2, 600, $now));
        self::assertSame(590, $store->consume(self::ACTOR, 2, 600, $now), 'until the window ends');
        self::assertNull($store->consume(hash('sha256', 'other'), 2, 600, $now), 'every actor has its own window');
        self::assertCount(3, $storage->rows);

        self::assertNull($store->consume(self::ACTOR, 2, 600, $now->modify('+590 seconds')), 'a new window');
        self::assertCount(1, $storage->rows, 'the rows of ended windows are removed');
        $storage->insert(SectionTestStore::RATES, 'damaged', 'not json', $now);
        self::assertNull($store->consume(self::ACTOR, 2, 600, $now->modify('+591 seconds')));
        self::assertCount(2, $storage->rows, 'a damaged row is removed too');
    }
}
