<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use InvalidArgumentException;

use function count;
use function get_debug_type;
use function sprintf;

/**
 * What a {@see SettingsSectionTesterInterface} answers (1.18): its checks, in order, and their overall status.
 *
 * The overall status is {@see TestStatus::Fail} when any check failed, {@see TestStatus::Ok} when at least one passed
 * and none failed, and {@see TestStatus::Skipped} otherwise (no check, or every check skipped).
 *
 * Immutable and framework-free (REQ-ARCH-001).
 */
final readonly class TestReport
{
    /** Most checks in one report. */
    public const MAX_CHECKS = 20;

    /** @var list<TestCheck> */
    public array $checks;

    public TestStatus $status;

    /**
     * @param iterable<mixed> $checks the checks, in display order
     *
     * @throws InvalidArgumentException for an item that is not a {@see TestCheck} or more than {@see self::MAX_CHECKS}
     */
    public function __construct(iterable $checks = [])
    {
        $list = [];
        foreach ($checks as $check) {
            if (!$check instanceof TestCheck) {
                throw new InvalidArgumentException(sprintf('A section test report holds TestCheck objects, got %s.', get_debug_type($check)));
            }
            $list[] = $check;
        }
        if (count($list) > self::MAX_CHECKS) {
            throw new InvalidArgumentException(sprintf('A section test report holds at most %d checks.', self::MAX_CHECKS));
        }
        $this->checks = $list;
        $this->status = $this->overall($list);
    }

    /**
     * A report of the given checks.
     */
    public static function of(TestCheck ...$checks): self
    {
        return new self($checks);
    }

    /**
     * @param list<TestCheck> $checks
     */
    private function overall(array $checks): TestStatus
    {
        $status = TestStatus::Skipped;
        foreach ($checks as $check) {
            if ($check->status === TestStatus::Fail) {
                return TestStatus::Fail;
            }
            if ($check->status === TestStatus::Ok) {
                $status = TestStatus::Ok;
            }
        }

        return $status;
    }
}
