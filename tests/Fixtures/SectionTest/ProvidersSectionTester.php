<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest;

use Nowo\InstanceSettingsBundle\Attribute\AsSettingsTester;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestContext;
use Nowo\InstanceSettingsBundle\SectionTest\SettingsSectionTesterInterface;
use Nowo\InstanceSettingsBundle\SectionTest\TestCheck;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;

/**
 * 1.18: a tester of the 1.17 expiring-secrets fixture section (`providers`), to check that the Test button, the locked
 * rows (reveal_in_input) and the expiry rows share one page.
 */
#[AsSettingsTester(section: 'providers')]
final class ProvidersSectionTester implements SettingsSectionTesterInterface
{
    public function test(SectionTestContext $context): TestReport
    {
        return TestReport::of($context->hasSecret('api_key') ? TestCheck::ok('calendar_oauth.test.token') : TestCheck::skipped('calendar_oauth.test.token'));
    }
}
