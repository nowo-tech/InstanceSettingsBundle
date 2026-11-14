<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use DateTimeImmutable;
use Throwable;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The last report of a section test (1.18), as stored in the bundle's store and shown under the Test button: the
 * section, the group, the overall status, the time, the actor and the checks (translation keys and redacted
 * parameters). Never a setting value: the checks went through the {@see LeakGuard} before they were stored.
 *
 * Immutable.
 */
final readonly class StoredTestReport
{
    /** Version of the stored JSON shape. */
    public const VERSION = 1;

    /**
     * @param string $section section name
     * @param string|null $group group code (null = the whole section)
     * @param TestStatus $status overall status
     * @param DateTimeImmutable $testedAt time of the test
     * @param string|null $actor user identifier of the actor (null = none resolvable)
     * @param list<TestCheck> $checks the checks, redacted
     */
    public function __construct(
        public string $section,
        public ?string $group,
        public TestStatus $status,
        public DateTimeImmutable $testedAt,
        public ?string $actor,
        public array $checks,
    ) {
    }

    /**
     * The stored report of a (redacted) report.
     */
    public static function of(string $section, ?string $group, TestReport $report, DateTimeImmutable $testedAt, ?string $actor): self
    {
        return new self($section, $group, $report->status, $testedAt, $actor, $report->checks);
    }

    /**
     * JSON form written to the store.
     */
    public function toJson(): string
    {
        $checks = [];
        foreach ($this->checks as $check) {
            $checks[] = $check->toArray();
        }

        return json_encode([
            'v'       => self::VERSION,
            'section' => $this->section,
            'group'   => $this->group,
            'status'  => $this->status->value,
            'at'      => $this->testedAt->format(DATE_ATOM),
            'actor'   => $this->actor,
            'checks'  => $checks,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A stored report read back, or null for anything unreadable (another version, a damaged row).
     */
    public static function fromJson(mixed $json): ?self
    {
        if (!is_string($json)) {
            return null;
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['v'] ?? null) !== self::VERSION || !is_string($data['section'] ?? null) || !is_string($data['at'] ?? null) || !is_array($data['checks'] ?? null)) {
                return null;
            }
            $group  = $data['group'] ?? null;
            $actor  = $data['actor'] ?? null;
            $checks = [];
            foreach ($data['checks'] as $check) {
                $checks[] = self::check($check);
            }

            return new self(
                $data['section'],
                is_string($group) ? $group : null,
                TestStatus::from(is_string($data['status'] ?? null) ? $data['status'] : ''),
                new DateTimeImmutable($data['at']),
                is_string($actor) ? $actor : null,
                $checks,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function check(mixed $check): TestCheck
    {
        $check  = is_array($check) ? $check : [];
        $label  = $check['label'] ?? null;
        $hint   = $check['hint'] ?? null;
        $domain = $check['domain'] ?? null;
        $params = $check['params'] ?? [];

        return new TestCheck(
            is_string($label) ? $label : '',
            TestStatus::from(is_string($check['status'] ?? null) ? $check['status'] : ''),
            is_string($hint) ? $hint : null,
            is_array($params) ? $params : [],
            is_string($domain) ? $domain : null,
        );
    }
}
