<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\SectionTest;

use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\SectionTest\LeakGuard;
use Nowo\InstanceSettingsBundle\SectionTest\TestCheck;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;
use Nowo\InstanceSettingsBundle\SectionTest\TestStatus;
use PHPUnit\Framework\TestCase;

/**
 * 1.18: the stored secrets of a section never reach a test report — whole, sliced, as a key or as a parameter.
 */
final class LeakGuardTest extends TestCase
{
    /** A throwaway value, never a real secret. */
    private const SECRET = 'Tk9vQ2wLx7Rp';

    public function testHits(): void
    {
        self::assertTrue(LeakGuard::hits(self::SECRET, [self::SECRET]), 'exact');
        self::assertTrue(LeakGuard::hits('Bearer ' . self::SECRET . ' rejected', [self::SECRET]), 'inside a text');
        self::assertTrue(LeakGuard::hits('…Q2wLx7…', [self::SECRET]), 'a slice of six characters');
        self::assertTrue(LeakGuard::hits('Tk9vQ2', [self::SECRET]), 'a prefix of six');
        self::assertFalse(LeakGuard::hits('Tk9vQ', [self::SECRET]), 'five characters are not enough');
        self::assertFalse(LeakGuard::hits('', [self::SECRET]));
        self::assertFalse(LeakGuard::hits('anything', ['']));
        self::assertFalse(LeakGuard::hits('anything', []));
        // A secret shorter than six characters is matched exactly only.
        self::assertTrue(LeakGuard::hits('abc', ['abc']));
        self::assertFalse(LeakGuard::hits('abcd', ['abc']));
        self::assertTrue(LeakGuard::hits('second', ['none12', 'second']), 'every secret is checked');
        self::assertSame(LeakGuard::REDACTED, LeakGuard::text('error: ' . self::SECRET, [self::SECRET]));
        self::assertSame('error: 401', LeakGuard::text('error: 401', [self::SECRET]));
    }

    public function testAReportWithoutHitsIsReturnedAsIs(): void
    {
        $report = TestReport::of(TestCheck::ok('a.b', 'c.d', ['%n%' => 1, '%f%' => 1.5, '%b%' => false, '%s%' => 'smtp.example.test']));

        self::assertSame([$report, 0], LeakGuard::report($report, [self::SECRET]));
        self::assertSame([$report, 0], LeakGuard::report($report, []));
    }

    public function testEveryHitIsRedacted(): void
    {
        $report = TestReport::of(
            TestCheck::fail('a.b', 'c.d', ['%exact%' => self::SECRET, '%slice%' => 'xQ2wLx7Ry', '%safe%' => 'host', '%flag%' => true]),
            TestCheck::fail(self::SECRET, 'c.d'),
            TestCheck::fail('a.b', 'pre.' . self::SECRET),
            TestCheck::ok('a.b', 'c.d', [], self::SECRET),
            TestCheck::ok('a.b', 'c.d', [self::SECRET => 'x', '%keep%' => 2]),
            TestCheck::skipped('a.b'),
        );

        [$redacted, $hits] = LeakGuard::report($report, [self::SECRET]);

        self::assertSame(6, $hits);
        self::assertSame(TestStatus::Fail, $redacted->status);
        self::assertSame(['%exact%' => LeakGuard::REDACTED, '%slice%' => LeakGuard::REDACTED, '%safe%' => 'host', '%flag%' => true], $redacted->checks[0]->hintParams);
        foreach ([1, 2, 3] as $index) {
            self::assertSame(LeakGuard::REDACTED_LABEL, $redacted->checks[$index]->labelKey);
            self::assertSame(LeakGuard::REDACTED_HINT, $redacted->checks[$index]->hintKey);
            self::assertSame(NowoInstanceSettingsBundle::TRANSLATION_DOMAIN, $redacted->checks[$index]->domain);
            self::assertSame([], $redacted->checks[$index]->hintParams);
        }
        self::assertSame(TestStatus::Ok, $redacted->checks[3]->status, 'the status is kept');
        self::assertSame(['%keep%' => 2], $redacted->checks[4]->hintParams, 'a parameter named after a secret is dropped');
        self::assertSame($report->checks[5], $redacted->checks[5]);
    }
}
