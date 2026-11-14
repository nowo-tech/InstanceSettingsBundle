<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use InvalidArgumentException;

use function count;
use function get_debug_type;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function mb_substr;
use function preg_match;
use function sprintf;

/**
 * One check of a section test (1.18): a translation key for its label, its status, and an optional hint (a translation
 * key with scalar parameters).
 *
 * Labels and hints are translation keys, never free text ({@see self::KEY_PATTERN}: no spaces), so a tester cannot put a
 * sentence, an exception message or a response body on the page. Parameters are scalars; strings are cut at
 * {@see self::MAX_PARAM_LENGTH} characters. The bundle checks every key and parameter against the section's stored
 * secrets before it renders, stores or logs a check ({@see LeakGuard}).
 *
 * Immutable and framework-free (REQ-ARCH-001).
 */
final readonly class TestCheck
{
    /** Translation keys: letters, digits, `_`, `.` and `-`, 1–128 characters, no spaces (no free text). */
    public const KEY_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.\-]{0,127}$/D';

    /** Parameter names: `%name%`, `{name}` or a bare `name` (1–32 word characters). */
    public const PARAM_PATTERN = '/^(?:%[A-Za-z0-9_]{1,32}%|\{[A-Za-z0-9_]{1,32}\}|[A-Za-z0-9_]{1,32})$/D';

    /** Longest string parameter kept, in characters (longer ones are cut). */
    public const MAX_PARAM_LENGTH = 200;

    /** Most parameters of one hint. */
    public const MAX_PARAMS = 10;

    /** @var array<string, bool|float|int|string> */
    public array $hintParams;

    /**
     * @param string $labelKey translation key of the check's label (e.g. `calendar_oauth.test.google.token`)
     * @param TestStatus $status outcome
     * @param string|null $hintKey translation key of the hint (e.g. `calendar_oauth.test.hint.http_status`); null = none
     * @param array<array-key, mixed> $hintParams scalar parameters of the hint (`%status%` => 401)
     * @param string|null $domain translation domain of the label and the hint (null = the section's domain)
     *
     * @throws InvalidArgumentException for a key that is not a translation key, a parameter that is not a scalar, or
     *                                  too many parameters
     */
    public function __construct(
        public string $labelKey,
        public TestStatus $status,
        public ?string $hintKey = null,
        array $hintParams = [],
        public ?string $domain = null,
    ) {
        $this->assertKey($labelKey, 'label');
        if ($hintKey !== null) {
            $this->assertKey($hintKey, 'hint');
        }
        if ($domain !== null) {
            $this->assertKey($domain, 'domain');
        }
        if (count($hintParams) > self::MAX_PARAMS) {
            throw new InvalidArgumentException(sprintf('A section test check has at most %d hint parameters.', self::MAX_PARAMS));
        }
        $params = [];
        foreach ($hintParams as $name => $value) {
            if (!is_string($name) || preg_match(self::PARAM_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException('A section test hint parameter name must match ' . self::PARAM_PATTERN . '.');
            }
            if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new InvalidArgumentException(sprintf('Section test hint parameter "%s" must be a scalar, got %s.', $name, get_debug_type($value)));
            }
            $params[$name] = is_string($value) ? mb_substr($value, 0, self::MAX_PARAM_LENGTH) : $value;
        }
        $this->hintParams = $params;
    }

    /**
     * A passed check.
     *
     * @param array<array-key, mixed> $hintParams
     */
    public static function ok(string $labelKey, ?string $hintKey = null, array $hintParams = [], ?string $domain = null): self
    {
        return new self($labelKey, TestStatus::Ok, $hintKey, $hintParams, $domain);
    }

    /**
     * A failed check.
     *
     * @param array<array-key, mixed> $hintParams
     */
    public static function fail(string $labelKey, ?string $hintKey = null, array $hintParams = [], ?string $domain = null): self
    {
        return new self($labelKey, TestStatus::Fail, $hintKey, $hintParams, $domain);
    }

    /**
     * A check that did not run.
     *
     * @param array<array-key, mixed> $hintParams
     */
    public static function skipped(string $labelKey, ?string $hintKey = null, array $hintParams = [], ?string $domain = null): self
    {
        return new self($labelKey, TestStatus::Skipped, $hintKey, $hintParams, $domain);
    }

    /**
     * Array form stored as the last report (keys, status and parameters only).
     *
     * @return array{label: string, status: string, hint: string|null, params: array<string, bool|float|int|string>, domain: string|null}
     */
    public function toArray(): array
    {
        return ['label' => $this->labelKey, 'status' => $this->status->value, 'hint' => $this->hintKey, 'params' => $this->hintParams, 'domain' => $this->domain];
    }

    private function assertKey(string $key, string $what): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(sprintf('A section test check %s must be a translation key matching %s (no free text).', $what, self::KEY_PATTERN));
        }
    }
}
