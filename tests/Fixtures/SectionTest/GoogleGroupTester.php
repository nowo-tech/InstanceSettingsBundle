<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest;

use Nowo\InstanceSettingsBundle\Attribute\AsSettingsTester;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestContext;
use Nowo\InstanceSettingsBundle\SectionTest\SettingsSectionTesterInterface;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;

/**
 * 1.18: the tester of the Google subtab of `calendar_oauth` (no Microsoft tester).
 */
#[AsSettingsTester(section: 'calendar_oauth', group: 'google')]
final class GoogleGroupTester implements SettingsSectionTesterInterface
{
    public function test(SectionTestContext $context): TestReport
    {
        return ScriptedChecks::report($context, 'google_client_secret');
    }
}
