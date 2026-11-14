<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\SectionTest;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\SectionTest\StoredTestReport;
use Nowo\InstanceSettingsBundle\SectionTest\TestCheck;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;
use Nowo\InstanceSettingsBundle\SectionTest\TestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const DATE_ATOM;

/**
 * 1.18: the last report round-trips through the store; anything unreadable is no report.
 */
final class StoredTestReportTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $at     = new DateTimeImmutable('2026-11-14T10:00:00+00:00');
        $report = StoredTestReport::of('mailer', 'smtp.relay', TestReport::of(TestCheck::fail('a.b', 'c.d', ['%code%' => 535, '%ok%' => false], 'app'), TestCheck::skipped('e.f')), $at, 'admin');

        $back = StoredTestReport::fromJson($report->toJson());

        self::assertInstanceOf(StoredTestReport::class, $back);
        self::assertSame('mailer', $back->section);
        self::assertSame('smtp.relay', $back->group);
        self::assertSame(TestStatus::Fail, $back->status);
        self::assertSame($at->format(DATE_ATOM), $back->testedAt->format(DATE_ATOM));
        self::assertSame('admin', $back->actor);
        self::assertEquals($report->checks, $back->checks);

        $anonymous = StoredTestReport::fromJson(StoredTestReport::of('mailer', null, new TestReport(), $at, null)->toJson());
        self::assertInstanceOf(StoredTestReport::class, $anonymous);
        self::assertNull($anonymous->group);
        self::assertNull($anonymous->actor);
        self::assertSame(TestStatus::Skipped, $anonymous->status);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function unreadable(): iterable
    {
        $valid = ['v' => 1, 'section' => 'mailer', 'group' => null, 'status' => 'ok', 'at' => '2026-11-14T10:00:00+00:00', 'actor' => null, 'checks' => []];

        yield 'not a string' => [3];
        yield 'not json' => ['{'];
        yield 'not an object' => ['"x"'];
        yield 'other version' => [json_encode(['v' => 2] + $valid)];
        yield 'no section' => [json_encode(['section' => 3] + $valid)];
        yield 'no time' => [json_encode(['at' => null] + $valid)];
        yield 'bad time' => [json_encode(['at' => 'not a time'] + $valid)];
        yield 'no checks' => [json_encode(['checks' => 'x'] + $valid)];
        yield 'bad status' => [json_encode(['status' => 'great'] + $valid)];
        yield 'no status' => [json_encode(['status' => null] + $valid)];
        yield 'bad check' => [json_encode(['checks' => ['x']] + $valid)];
        yield 'free text check' => [json_encode(['checks' => [['label' => 'A sentence', 'status' => 'ok']]] + $valid)];
        yield 'bad check status' => [json_encode(['checks' => [['label' => 'a.b', 'status' => 3]]] + $valid)];
    }

    #[DataProvider('unreadable')]
    public function testAnythingUnreadableIsNoReport(mixed $json): void
    {
        self::assertNull(StoredTestReport::fromJson($json));
    }

    public function testOddButReadableFieldsFallBack(): void
    {
        $report = StoredTestReport::fromJson((string) json_encode(['v' => 1, 'section' => 'mailer', 'group' => 3, 'status' => 'ok', 'at' => '2026-11-14T10:00:00+00:00', 'actor' => 4, 'checks' => [['label' => 'a.b', 'status' => 'ok', 'hint' => 5, 'params' => 'x', 'domain' => 6]]]));

        self::assertInstanceOf(StoredTestReport::class, $report);
        self::assertNull($report->group);
        self::assertNull($report->actor);
        self::assertNull($report->checks[0]->hintKey);
        self::assertSame([], $report->checks[0]->hintParams);
        self::assertNull($report->checks[0]->domain);
    }
}
