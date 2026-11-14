<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\SectionTest;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestStore;
use Nowo\InstanceSettingsBundle\SectionTest\StoredTestReport;
use Nowo\InstanceSettingsBundle\SectionTest\TestCheck;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\UniqueViolationRepository;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function hash;
use function strlen;

/**
 * 1.18: the last reports, the running markers and the built-in rate windows live in the settings table under reserved
 * section names; row names are hashes.
 */
final class SectionTestStoreTest extends TestCase
{
    private const ACTOR = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    public function testLastReportsAreKeptPerSectionAndGroup(): void
    {
        $repository = new InMemorySettingValueRepository();
        $store      = new SectionTestStore($repository);
        $at         = new DateTimeImmutable('2026-11-14 10:00:00');

        $store->saveReport(StoredTestReport::of('calendar', null, TestReport::of(TestCheck::ok('a.b')), $at, 'admin'));
        $store->saveReport(StoredTestReport::of('calendar', 'google', TestReport::of(TestCheck::fail('a.b')), $at, 'admin'));
        $store->saveReport(StoredTestReport::of('mailer', null, TestReport::of(TestCheck::ok('a.b')), $at, 'admin'));
        $store->saveReport(StoredTestReport::of('calendar', 'google', TestReport::of(TestCheck::skipped('a.b')), $at->modify('+1 minute'), 'super'));

        $reports = $store->lastReports('calendar');
        self::assertSame(['', 'google'], array_keys($reports));
        self::assertSame('super', $reports['google']->actor, 'replaced, not added');
        self::assertCount(3, $repository->rows);
        foreach ($repository->rows as $row) {
            self::assertSame(SectionTestStore::REPORTS, $row->getSection());
            self::assertSame(64, strlen($row->getName()), 'a hash fits the name column');
        }
        self::assertSame([], $store->lastReports('nope'));
    }

    public function testDamagedOrMisplacedReportsAreIgnored(): void
    {
        $at = new DateTimeImmutable();
        // A report stored under another group's row name, and a damaged row.
        $misplaced = (new SettingValue(SectionTestStore::REPORTS, SectionTestStore::reportName('calendar', 'other'), $at))->storeValue(StoredTestReport::of('calendar', 'google', new TestReport(), $at, null)->toJson(), $at);
        $damaged   = (new SettingValue(SectionTestStore::REPORTS, SectionTestStore::reportName('calendar', null), $at))->storeValue('{', $at);
        $store     = new SectionTestStore(new UniqueViolationRepository([$misplaced, $damaged]));

        self::assertSame([], $store->lastReports('calendar'));
    }

    public function testOneRunningMarkerPerActorAndSection(): void
    {
        $repository = new InMemorySettingValueRepository();
        $store      = new SectionTestStore($repository);
        $now        = new DateTimeImmutable('2026-11-14 10:00:00');

        self::assertTrue($store->acquire(self::ACTOR, 'calendar', $now, 120));
        self::assertFalse($store->acquire(self::ACTOR, 'calendar', $now->modify('+119 seconds'), 120), 'still running');
        self::assertTrue($store->acquire(self::ACTOR, 'mailer', $now, 120), 'another section');
        self::assertTrue($store->acquire(hash('sha256', 'other'), 'calendar', $now, 120), 'another actor');
        self::assertTrue($store->acquire(self::ACTOR, 'calendar', $now->modify('+121 seconds'), 120), 'a stale marker is taken over');
        self::assertCount(3, $repository->rows);

        $store->release(self::ACTOR, 'calendar');
        $store->release(self::ACTOR, 'calendar');
        self::assertCount(2, $repository->rows);
        self::assertTrue($store->acquire(self::ACTOR, 'calendar', $now, 120));
    }

    public function testAConcurrentInsertMeansBusy(): void
    {
        self::assertFalse((new SectionTestStore(new UniqueViolationRepository()))->acquire(self::ACTOR, 'calendar', new DateTimeImmutable(), 120));
    }

    public function testTheBuiltInFixedWindow(): void
    {
        $repository = new InMemorySettingValueRepository();
        $store      = new SectionTestStore($repository);
        $now        = new DateTimeImmutable('2026-11-14 10:00:00');

        self::assertNull($store->consume(self::ACTOR, 2, 600, $now));
        self::assertNull($store->consume(self::ACTOR, 2, 600, $now->modify('+10 seconds')));
        self::assertSame(590, $store->consume(self::ACTOR, 2, 600, $now->modify('+10 seconds')));
        self::assertSame(1, $store->consume(self::ACTOR, 2, 600, $now->modify('+599 seconds')), 'the last second of the window');
        self::assertNull($store->consume(self::ACTOR, 2, 600, $now->modify('+600 seconds')), 'a new window');
        self::assertNull($store->consume(hash('sha256', 'other'), 2, 600, $now), 'every actor has its own window');
        self::assertCount(2, $repository->rows);
    }

    public function testADamagedWindowStartsAgainAndAConcurrentFirstWindowCountsAsAccepted(): void
    {
        $now        = new DateTimeImmutable('2026-11-14 10:00:00');
        $repository = new InMemorySettingValueRepository();
        $damaged    = (new SettingValue(SectionTestStore::RATES, self::ACTOR, $now))->storeValue('not json', $now);
        $repository->apply([$damaged], []);
        $store = new SectionTestStore($repository);
        self::assertNull($store->consume(self::ACTOR, 1, 600, $now));
        self::assertSame(600, $store->consume(self::ACTOR, 1, 600, $now));

        $notAString = (new SettingValue(SectionTestStore::RATES, self::ACTOR, $now))->storeValue(3, $now);
        self::assertNull((new SectionTestStore(new UniqueViolationRepository([$notAString])))->consume(self::ACTOR, 1, 600, $now));
        self::assertNull((new SectionTestStore(new UniqueViolationRepository()))->consume(self::ACTOR, 1, 600, $now));
    }
}
