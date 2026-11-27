<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Ui;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\SectionTest\StoredTestReport;
use Nowo\UiKitBundle\Date\DateFormatterInterface;
use Nowo\UiKitBundle\Date\IsoDateFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;

use function date_default_timezone_get;
use function is_bool;

/**
 * The translated view of a stored section test report (1.18): what the page shows under a Test button and what the test
 * endpoint answers ({@see SettingsController::testSection()}), so both carry the same texts.
 *
 * Every text is a translation of a key with scalar parameters (already redacted); the page escapes it (Twig
 * autoescape, the script's `textContent`). No setting value ever reaches this view.
 *
 * 1.24: the time of the summary is a date for people, formatted by the host's UiKit {@see DateFormatterInterface}
 * (kind `datetime` with the zone; UiKit's {@see IsoDateFormatter} without one: `2026-11-27 16:14 UTC`, the 1.18 text).
 * `time` carries that text, in the application's time zone, and `testedAt` the ISO-8601 instant, so the page wraps the
 * time in `<time datetime>` (`nowo_time_tag`) and the script does the same with the endpoint's answer.
 */
final class SectionTestView
{
    /**
     * Time format of the summary line until 1.23; kept for BC, the summary now goes through
     * {@see DateFormatterInterface} (its default prints the same text).
     *
     * @deprecated since 1.24, format through {@see DateFormatterInterface} (kind `datetime`, zone `true`)
     */
    public const TIME_FORMAT = 'Y-m-d H:i T';

    /**
     * @param string $domain translation domain of the section (checks without a domain of their own)
     * @param DateFormatterInterface|null $dates the host's date formatter (null: UiKit's ISO default)
     *
     * @return array{section: string, group: string|null, status: string, statusLabel: string, testedAt: string, time: string, summary: string, checks: list<array{status: string, statusLabel: string, label: string, hint: string|null}>}
     */
    public static function json(StoredTestReport $report, string $domain, ?TranslatorInterface $translator, ?DateFormatterInterface $dates = null): array
    {
        $checks = [];
        foreach ($report->checks as $check) {
            $checkDomain = $check->domain ?? $domain;
            $checks[]    = [
                'status'      => $check->status->value,
                'statusLabel' => self::trans($translator, 'test.status.' . $check->status->value),
                'label'       => self::trans($translator, $check->labelKey, [], $checkDomain),
                'hint'        => $check->hintKey === null ? null : self::trans($translator, $check->hintKey, self::params($check->hintParams), $checkDomain),
            ];
        }
        $status = self::trans($translator, 'test.status.' . $report->status->value);
        // In the application's zone, as `|date` and `nowo_time_tag` show it on the page.
        $time = ($dates ?? new IsoDateFormatter())->format($report->testedAt->setTimezone(new DateTimeZone(date_default_timezone_get())), DateFormatterInterface::DATETIME, true);

        return [
            'section'     => $report->section,
            'group'       => $report->group,
            'status'      => $report->status->value,
            'statusLabel' => $status,
            'testedAt'    => $report->testedAt->format(DateTimeImmutable::ATOM),
            'time'        => $time,
            'summary'     => $report->actor === null
                ? self::trans($translator, 'test.summary_anonymous', ['%status%' => $status, '%time%' => $time])
                : self::trans($translator, 'test.summary', ['%status%' => $status, '%time%' => $time, '%actor%' => $report->actor]),
            'checks' => $checks,
        ];
    }

    /**
     * Hint parameters as the translator takes them (booleans as `1` / `0`).
     *
     * @param array<string, bool|float|int|string> $params
     *
     * @return array<string, float|int|string>
     */
    public static function params(array $params): array
    {
        $out = [];
        foreach ($params as $name => $value) {
            $out[$name] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }

        return $out;
    }

    /**
     * @param array<string, float|int|string> $params
     */
    private static function trans(?TranslatorInterface $translator, string $key, array $params = [], string $domain = NowoInstanceSettingsBundle::TRANSLATION_DOMAIN): string
    {
        return $translator instanceof TranslatorInterface ? $translator->trans($key, $params, $domain) : $key;
    }
}
