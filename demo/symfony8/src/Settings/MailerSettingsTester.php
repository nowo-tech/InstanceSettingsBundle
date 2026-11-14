<?php

declare(strict_types=1);

namespace App\Settings;

use Nowo\InstanceSettingsBundle\Attribute\AsSettingsTester;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestContext;
use Nowo\InstanceSettingsBundle\SectionTest\SettingsSectionTesterInterface;
use Nowo\InstanceSettingsBundle\SectionTest\TestCheck;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;

use function filter_var;
use function in_array;
use function is_string;
use function parse_url;

use const FILTER_VALIDATE_EMAIL;
use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

/**
 * InstanceSettings 1.18: the Test button of the demo mailer section. A real tester would open the SMTP connection
 * (with $context->timeBudgetSeconds() as its timeout); the demo stays offline and checks the stored values only:
 * the DSN is stored and parses to a known transport, and the sender is an e-mail address.
 *
 * Only translation keys and scalar parameters are returned: never the DSN, never a part of it.
 */
#[AsSettingsTester(section: 'mailer')]
final class MailerSettingsTester implements SettingsSectionTesterInterface
{
    public function test(SectionTestContext $context): TestReport
    {
        $dsn    = $context->secret('dsn');
        $scheme = $dsn === null ? null : parse_url($dsn, PHP_URL_SCHEME);
        $host   = $dsn === null ? null : parse_url($dsn, PHP_URL_HOST);

        $transport = match (true) {
            $dsn === null                                                                                   => TestCheck::skipped('mailer.test.transport', 'mailer.test.hint.no_dsn'),
            is_string($scheme) && in_array($scheme, ['smtp', 'smtps', 'null'], true) && ($scheme === 'null' || is_string($host)) => TestCheck::ok('mailer.test.transport', 'mailer.test.hint.transport', ['%scheme%' => $scheme]),
            default                                                                                         => TestCheck::fail('mailer.test.transport', 'mailer.test.hint.bad_dsn'),
        };
        $sender = filter_var($context->string('from'), FILTER_VALIDATE_EMAIL) !== false
            ? TestCheck::ok('mailer.test.sender')
            : TestCheck::fail('mailer.test.sender', 'mailer.test.hint.bad_sender');

        return TestReport::of($transport, $sender);
    }
}
