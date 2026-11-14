<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

/**
 * Outcome of one check of a section test, and the overall outcome of a {@see TestReport} (1.18).
 */
enum TestStatus: string
{
    /** The check passed: the stored values connect. */
    case Ok = 'ok';

    /** The check failed. */
    case Fail = 'fail';

    /** The check did not run (e.g. a provider that is not selected, or nothing stored to test). */
    case Skipped = 'skipped';
}
