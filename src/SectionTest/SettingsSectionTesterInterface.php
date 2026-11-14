<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use Nowo\InstanceSettingsBundle\Attribute\AsSettingsTester;

/**
 * Tests that the STORED values of a settings section, or of one field group of it, really connect (1.18): the Test
 * button beside Save. Register an implementation with {@see AsSettingsTester} (`#[AsSettingsTester(section: 'mailer')]`
 * or `#[AsSettingsTester(section: 'calendar_oauth', group: 'google')]`).
 *
 * Contract:
 *
 * - Read the stored values through the {@see SectionTestContext} only; it stops answering once the call returns.
 * - Answer with translation keys and scalar parameters ({@see TestCheck}); never free text, never a value. The bundle
 *   redacts any parameter that matches a stored secret of the section, and never shows an exception message.
 * - Finish within {@see self::TIME_BUDGET_SECONDS} ({@see SectionTestContext::timeBudgetSeconds()}): set it as the
 *   timeout of every network call. The bundle cannot stop host code, but it logs a tester that ran over.
 * - Throwing is allowed: the bundle turns any Throwable into a failed check with a generic hint and logs the exception
 *   at debug level, redacted.
 * - Stay stateless (FrankenPHP worker mode, kernel reset off): no property may keep a value, a token or a response.
 */
interface SettingsSectionTesterInterface
{
    /** Time budget of one test call, in seconds (use it as the timeout of every network call). */
    public const TIME_BUDGET_SECONDS = 10;

    /**
     * Runs the checks against the stored values of `$context->section` (and `$context->group`, when set).
     */
    public function test(SectionTestContext $context): TestReport;
}
