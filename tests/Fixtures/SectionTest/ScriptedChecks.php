<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest;

use Nowo\InstanceSettingsBundle\SectionTest\SectionTestContext;
use Nowo\InstanceSettingsBundle\SectionTest\TestCheck;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

use function implode;
use function substr;

/**
 * 1.18: what the fixture testers answer, chosen by the stored `mode` of the section (so the testers stay stateless).
 *
 * - `ok` (default): one passed and one skipped check, the hint naming the group's fields
 * - `fail`: one failed check
 * - `leak`: a tester trying to leak the stored secret (whole, a slice, as a label key, as a parameter name)
 * - `throw`: an exception whose message holds the secret
 * - `free_text`: a sentence instead of a translation key
 * - `slow`: the (mock) clock moves past the time budget
 */
final class ScriptedChecks
{
    public static function report(SectionTestContext $context, string $secretField, ?ClockInterface $clock = null): TestReport
    {
        $secret = $context->secret($secretField) ?? '';

        return match ($context->string('mode')) {
            'fail' => TestReport::of(TestCheck::fail('calendar_oauth.test.token', 'calendar_oauth.test.hint.status', ['%status%' => 401])),
            'leak' => TestReport::of(
                TestCheck::fail('calendar_oauth.test.token', 'calendar_oauth.test.hint.leak', [
                    '%exact%' => $secret,
                    '%part%'  => 'x' . substr($secret, 3, 7) . 'y',
                    '%safe%'  => 'oauth2.googleapis.com',
                    '%code%'  => 401,
                ]),
                TestCheck::fail($secret, 'calendar_oauth.test.hint.status', ['%status%' => 500]),
                TestCheck::ok('calendar_oauth.test.scope', 'calendar_oauth.test.hint.status', ['%status%' => true]),
            ),
            'throw'     => throw new RuntimeException('token endpoint rejected client secret ' . $secret),
            'free_text' => TestReport::of(TestCheck::ok('The token endpoint answered fine')),
            'slow'      => self::slow($clock),
            default     => TestReport::of(
                TestCheck::ok('calendar_oauth.test.token', 'calendar_oauth.test.hint.fields', ['%fields%' => implode(',', $context->groupFields()), '%group%' => $context->group ?? 'section']),
                TestCheck::skipped('calendar_oauth.test.refresh'),
            ),
        };
    }

    private static function slow(?ClockInterface $clock): TestReport
    {
        if ($clock instanceof MockClock) {
            $clock->sleep(12);
        }

        return TestReport::of(TestCheck::ok('calendar_oauth.test.token'));
    }
}
