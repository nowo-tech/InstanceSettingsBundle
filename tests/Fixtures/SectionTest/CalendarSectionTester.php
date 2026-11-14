<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest;

use Nowo\InstanceSettingsBundle\Attribute\AsSettingsTester;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestContext;
use Nowo\InstanceSettingsBundle\SectionTest\SettingsSectionTesterInterface;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;
use Psr\Clock\ClockInterface;

/**
 * 1.18: the section tester of `calendar_oauth` (the whole section).
 */
#[AsSettingsTester(section: 'calendar_oauth')]
final readonly class CalendarSectionTester implements SettingsSectionTesterInterface
{
    public function __construct(
        private ?ClockInterface $clock = null,
    ) {
    }

    public function test(SectionTestContext $context): TestReport
    {
        return ScriptedChecks::report($context, 'microsoft_client_secret', $this->clock);
    }
}
