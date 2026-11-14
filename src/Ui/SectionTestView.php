<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Ui;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\SectionTest\StoredTestReport;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_bool;

/**
 * The translated view of a stored section test report (1.18): what the page shows under a Test button and what the test
 * endpoint answers ({@see SettingsController::testSection()}), so both carry the same texts.
 *
 * Every text is a translation of a key with scalar parameters (already redacted); the page escapes it (Twig
 * autoescape, the script's `textContent`). No setting value ever reaches this view.
 */
final class SectionTestView
{
    /** Time format of the summary line. */
    public const TIME_FORMAT = 'Y-m-d H:i T';

    /**
     * @param string $domain translation domain of the section (checks without a domain of their own)
     *
     * @return array{section: string, group: string|null, status: string, statusLabel: string, testedAt: string, summary: string, checks: list<array{status: string, statusLabel: string, label: string, hint: string|null}>}
     */
    public static function json(StoredTestReport $report, string $domain, ?TranslatorInterface $translator): array
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

        return [
            'section'     => $report->section,
            'group'       => $report->group,
            'status'      => $report->status->value,
            'statusLabel' => $status,
            'testedAt'    => $report->testedAt->format(DateTimeImmutable::ATOM),
            'summary'     => $report->actor === null
                ? self::trans($translator, 'test.summary_anonymous', ['%status%' => $status, '%time%' => $report->testedAt->format(self::TIME_FORMAT)])
                : self::trans($translator, 'test.summary', ['%status%' => $status, '%time%' => $report->testedAt->format(self::TIME_FORMAT), '%actor%' => $report->actor]),
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string, bool|float|int|string> $params
     *
     * @return array<string, float|int|string>
     */
    private static function params(array $params): array
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
