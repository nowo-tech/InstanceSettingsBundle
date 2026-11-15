<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use SensitiveParameter;

use function base64_encode;
use function bin2hex;
use function is_bool;
use function is_string;
use function json_encode;
use function rawurlencode;
use function str_repeat;
use function strlen;
use function strrev;
use function strtolower;
use function strtr;
use function substr;
use function urlencode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Keeps the stored secrets of a section out of a section test report (1.18), best effort: every key and every parameter
 * of every check, the translated texts and the debug line of a throwing tester are compared with each stored secret of
 * the section (raw, common encodings, any case) before they are rendered, stored or logged.
 *
 * A text is a hit when it is the secret itself, or when it contains any part of the secret of
 * {@see self::MIN_SUBSTRING} characters or more (so a prefix, a suffix or a slice of a token is caught as well as the
 * whole token). A secret shorter than that is matched exactly only. A hit parameter becomes {@see self::REDACTED}; a
 * check whose label or hint key is a hit is replaced by the bundle's generic redacted check.
 *
 * Worker-safe: a guard ({@see self::of()}) is built per call and held in the caller's local variables only; nothing
 * is static or kept between requests.
 *
 * @internal not covered by the backward compatibility promise
 */
final readonly class LeakGuard
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
     * @param array<string, true> $exact every secret, lowercased (matched as the whole text)
     * @param array<int, array<string, true>> $parts every part looked for inside a text, lowercased, by length
     */
    private function __construct(
        private array $exact,
        private array $parts,
    ) {
    }

    /**
     * A guard for `$secrets`: the parts of every form of every secret are indexed once (audit PU-D1-I1, 1.18.1), so
     * each text is then read once per part length, whatever the length of the secrets. Built per call and never kept
     * (worker-safe).
     *
     * @param list<string> $secrets stored secrets of the section (decrypted, never logged)
     */
    public static function of(#[SensitiveParameter] array $secrets): self
    {
        $exact = [];
        $parts = [];
        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            $exact[strtolower($secret)] = true;
            if (strlen($secret) < self::MIN_SUBSTRING) {
                continue;
            }
            foreach (self::variants($secret) as [$variant, $window]) {
                $variant = strtolower($variant);
                $length  = strlen($variant);
                for ($offset = 0; $offset + $window <= $length; ++$offset) {
                    $parts[$window][substr($variant, $offset, $window)] = true;
                }
            }
        }

        return new self($exact, $parts);
    }

    /**
     * Whether `$text` holds a secret, or a part of one of at least {@see self::MIN_SUBSTRING} characters, in its raw
     * form or in a common encoding (audit PU-L1): reversed, URL (`rawurlencode` / `urlencode`), JSON-escaped, hex and
     * base64 / base64url at any alignment (so `base64("client:" . $secret)`, a Basic header, is caught). Matching ignores
     * case. A secret shorter than {@see self::MIN_SUBSTRING} characters is matched as the whole text only.
     *
     * Best effort: it catches accidental echoes, not a tester that transforms a secret on purpose.
     */
    public function matches(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        $haystack = strtolower($text);
        if (isset($this->exact[$haystack])) {
            return true;
        }
        $length = strlen($haystack);
        foreach ($this->parts as $window => $parts) {
            for ($offset = 0; $offset + $window <= $length; ++$offset) {
                if (isset($parts[substr($haystack, $offset, $window)])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * {@see self::matches()} with a guard built for `$secrets` (one text).
     *
     * @param list<string> $secrets stored secrets of the section (decrypted, never logged)
     */
    public static function hits(string $text, #[SensitiveParameter] array $secrets): bool
    {
        return self::of($secrets)->matches($text);
    }

    /**
     * The forms of a secret looked for, each with the length of the parts searched for (the encoded length of
     * {@see self::MIN_SUBSTRING} bytes).
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function variants(#[SensitiveParameter] string $secret): array
    {
        $json     = json_encode($secret, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $variants = [
            [$secret, self::MIN_SUBSTRING],
            [strrev($secret), self::MIN_SUBSTRING],
            [rawurlencode($secret), self::MIN_SUBSTRING],
            [urlencode($secret), self::MIN_SUBSTRING],
            [substr($json, 1, -1), self::MIN_SUBSTRING],
            [bin2hex($secret), 2 * self::MIN_SUBSTRING],
        ];
        // Base64 of the secret after 0, 1 or 2 other bytes: the groups holding foreign bytes (first and last four
        // characters) are dropped, what is left encodes the secret alone.
        for ($shift = 0; $shift < 3; ++$shift) {
            $encoded    = base64_encode(str_repeat("\0", $shift) . $secret);
            $core       = substr($encoded, $shift === 0 ? 0 : 4, -4);
            $variants[] = [$core, 8];
            $variants[] = [strtr($core, '+/', '-_'), 8];
        }

        return $variants;
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
        $guard  = self::of($secrets);
        $hits   = 0;
        $checks = [];
        foreach ($report->checks as $check) {
            if ($guard->matches($check->labelKey) || ($check->hintKey !== null && $guard->matches($check->hintKey)) || ($check->domain !== null && $guard->matches($check->domain))) {
                ++$hits;
                $checks[] = new TestCheck(self::REDACTED_LABEL, $check->status, self::REDACTED_HINT, [], NowoInstanceSettingsBundle::TRANSLATION_DOMAIN);

                continue;
            }
            $params  = [];
            $changed = false;
            foreach ($check->hintParams as $name => $value) {
                $text = is_string($value) ? $value : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
                if ($guard->matches($name) || $guard->matches($text)) {
                    ++$hits;
                    $changed = true;
                    if ($guard->matches($name)) {
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
