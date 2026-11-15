<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\SectionTest;

use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\SectionTest\LeakGuard;
use Nowo\InstanceSettingsBundle\SectionTest\TestCheck;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;
use Nowo\InstanceSettingsBundle\SectionTest\TestStatus;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function bin2hex;
use function hash;
use function json_encode;
use function rawurlencode;
use function rtrim;
use function str_repeat;
use function strrev;
use function strtolower;
use function strtoupper;
use function strtr;
use function substr;
use function urlencode;

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

    public function testEncodingsAndCaseOfASecretAreHits(): void
    {
        // Audit PU-L1: the common encodings a tester may echo by accident.
        $secret = 'ThrowAwayLeakGuardFixture';
        foreach ([
            'base64'     => base64_encode($secret),
            'base64 url' => rtrim(strtr(base64_encode('?' . $secret), '+/', '-_'), '='),
            'basic auth' => 'Basic ' . base64_encode('client:' . $secret),
            'basic odd'  => base64_encode('ab:' . $secret),
            'hex'        => bin2hex($secret),
            'hex upper'  => strtoupper(bin2hex($secret)),
            'lower'      => strtolower($secret),
            'upper'      => strtoupper($secret),
            'reversed'   => strrev($secret),
            'json'       => (string) json_encode(['k' => 'a/b"' . $secret]),
        ] as $what => $encoded) {
            self::assertTrue(LeakGuard::hits('value: ' . $encoded . ' end', [$secret]), $what);
        }
        $special = 'a+b/c=d+e/f=g+h';
        self::assertTrue(LeakGuard::hits('?q=' . rawurlencode($special), [$special]), 'URL encoded');
        self::assertTrue(LeakGuard::hits('?q=' . urlencode($special), [$special]), 'form encoded');
        self::assertTrue(LeakGuard::hits((string) json_encode($special), [$special]), 'JSON escaped');
        self::assertSame(LeakGuard::REDACTED, LeakGuard::text('auth ' . base64_encode($secret), [$secret]));
        // Unrelated hex / base64 strings stay.
        self::assertFalse(LeakGuard::hits(hash('sha256', 'other') . ' ' . base64_encode('unrelated value'), [$secret]));
        // Secrets shorter than six characters: exact match only (case-insensitive).
        self::assertTrue(LeakGuard::hits('PIN', ['pin']));
        self::assertFalse(LeakGuard::hits('pin=48213', ['48213']));
    }

    public function testAGuardBuiltOnceMatchesEveryTextLikeHits(): void
    {
        // Audit PU-D1-I1 (1.18.1): the parts of every form of every secret are indexed once per guard, then each text is
        // read once, instead of searching each part in each text; what is a hit does not change.
        $secrets = [self::SECRET, 'ThrowAwayLeakGuardFixture', 'a+b/c=d+e/f=g+h', 'pin', ''];
        $guard   = LeakGuard::of($secrets);
        foreach ([
            self::SECRET, 'Bearer ' . self::SECRET, '…Q2wLx7…', 'Tk9vQ', '', 'PIN', 'pin=1', 'Basic ' . base64_encode('client:ThrowAwayLeakGuardFixture'),
            bin2hex('ThrowAwayLeakGuardFixture'), strrev(self::SECRET), '?q=' . rawurlencode('a+b/c=d+e/f=g+h'), 'error: 401', 'oauth2.googleapis.com',
        ] as $text) {
            self::assertSame(LeakGuard::hits($text, $secrets), $guard->matches($text), $text);
        }
        self::assertFalse(LeakGuard::of([])->matches('anything'));
        self::assertTrue(LeakGuard::of(['abc'])->matches('ABC'), 'a short secret: exact, any case');

        // A long structured secret (a 2 KB service-account JSON) against the largest report stays cheap.
        $long  = (string) json_encode(['type' => 'service_account', 'private_key' => str_repeat('MIIEvQIBADANBgkqhkiG9w0BAQEFAASC', 60)]);
        $guard = LeakGuard::of([$long]);
        $texts = [];
        for ($i = 0; $i < 200; ++$i) {
            $texts[] = str_repeat('connection refused by host-' . $i . ' ', 7);
        }
        foreach ($texts as $text) {
            self::assertFalse($guard->matches($text));
        }
        self::assertTrue($guard->matches('key: ' . substr($long, 900, 6)));
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
