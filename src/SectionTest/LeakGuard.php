<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use SensitiveParameter;

use function is_bool;
use function is_string;
use function str_contains;
use function strlen;
use function substr;

/**
 * Keeps the stored secrets of a section out of a section test report (1.18): every key and every parameter of every
 * check is compared with each stored secret of the section before the report is rendered, stored or logged.
 *
 * A text is a hit when it is the secret itself, or when it contains any part of the secret of
 * {@see self::MIN_SUBSTRING} characters or more (so a prefix, a suffix or a slice of a token is caught as well as the
 * whole token). A secret shorter than that is matched exactly only. A hit parameter becomes {@see self::REDACTED}; a
 * check whose label or hint key is a hit is replaced by the bundle's generic redacted check.
 *
 * Pure and stateless (worker-safe): the secrets live in the caller's local variables only.
 *
 * @internal not covered by the backward compatibility promise
 */
final class LeakGuard
{
    /** Shortest part of a secret looked for inside a text. */
    public const MIN_SUBSTRING = 6;

    /** What a redacted parameter shows. */
    public const REDACTED = '[redacted]';

    /** Label key (bundle domain) of a check whose own label or hint was redacted. */
    public const REDACTED_LABEL = 'test.check.redacted';

    /** Hint key (bundle domain) of a check whose own label or hint was redacted. */
    public const REDACTED_HINT = 'test.hint.redacted';

    /**
     * Whether `$text` holds a secret, or a part of one of at least {@see self::MIN_SUBSTRING} characters.
     *
     * @param list<string> $secrets stored secrets of the section (decrypted, never logged)
     */
    public static function hits(string $text, #[SensitiveParameter] array $secrets): bool
    {
        if ($text === '') {
            return false;
        }
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            if ($text === $secret) {
                return true;
            }
            $length = strlen($secret);
            for ($offset = 0; $offset + self::MIN_SUBSTRING <= $length; ++$offset) {
                if (str_contains($text, substr($secret, $offset, self::MIN_SUBSTRING))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The report with every hit redacted, and the number of hits.
     *
     * @param list<string> $secrets stored secrets of the section
     *
     * @return array{0: TestReport, 1: int}
     */
    public static function report(TestReport $report, #[SensitiveParameter] array $secrets): array
    {
        if ($secrets === []) {
            return [$report, 0];
        }
        $hits   = 0;
        $checks = [];
        foreach ($report->checks as $check) {
            if (self::hits($check->labelKey, $secrets) || ($check->hintKey !== null && self::hits($check->hintKey, $secrets)) || ($check->domain !== null && self::hits($check->domain, $secrets))) {
                ++$hits;
                $checks[] = new TestCheck(self::REDACTED_LABEL, $check->status, self::REDACTED_HINT, [], NowoInstanceSettingsBundle::TRANSLATION_DOMAIN);

                continue;
            }
            $params  = [];
            $changed = false;
            foreach ($check->hintParams as $name => $value) {
                $text = is_string($value) ? $value : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
                if (self::hits($name, $secrets) || self::hits($text, $secrets)) {
                    ++$hits;
                    $changed = true;
                    if (self::hits($name, $secrets)) {
                        continue;
                    }
                    $value = self::REDACTED;
                }
                $params[$name] = $value;
            }
            $checks[] = $changed ? new TestCheck($check->labelKey, $check->status, $check->hintKey, $params, $check->domain) : $check;
        }

        return [$hits === 0 ? $report : new TestReport($checks), $hits];
    }

    /**
     * `$text`, or {@see self::REDACTED} when it is a hit (exception messages before they are logged).
     *
     * @param list<string> $secrets stored secrets of the section
     */
    public static function text(string $text, #[SensitiveParameter] array $secrets): string
    {
        return self::hits($text, $secrets) ? self::REDACTED : $text;
    }
}
