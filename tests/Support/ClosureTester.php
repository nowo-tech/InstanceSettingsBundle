<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Closure;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestContext;
use Nowo\InstanceSettingsBundle\SectionTest\SettingsSectionTesterInterface;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;

/**
 * 1.18: a section tester running a closure (unit tests).
 */
final readonly class ClosureTester implements SettingsSectionTesterInterface
{
    /**
     * @param Closure(SectionTestContext): TestReport $test
     */
    public function __construct(
        private Closure $test,
    ) {
    }

    public function test(SectionTestContext $context): TestReport
    {
        return ($this->test)($context);
    }
}
