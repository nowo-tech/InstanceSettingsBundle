<?php

declare(strict_types=1);

namespace App\Command;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Seeds realistic demo settings (idempotent). Secrets are obvious placeholders.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Seed demo instance settings (idempotent)')]
final class DemoSeedCommand extends Command
{
    public function __construct(
        private readonly InstanceSettingsInterface $settings,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $changed = [
            ...$this->settings->save('site', [
                'name'        => 'Acme Ops',
                'footer'      => 'Acme Ops — status and incident tooling.',
                'support_url' => 'https://support.example.test',
                'page_size'   => 25,
                'theme'       => 'auto',
            ]),
            ...$this->settings->save('mailer', [
                'from'           => 'ops@example.test',
                'from_name'      => 'Acme Ops',
                'dsn'            => 'smtp://demo:REDACTED-DEMO@mailpit:1025',
                'test_recipient' => 'admin@example.test',
            ]),
            ...$this->settings->save('sms', [
                'provider'   => 'twilio',
                'sender'     => 'ACME',
                'account_id' => 'AC-DEMO-ACCOUNT',
            ]),
        ];

        $output->writeln(sprintf('Demo instance settings seeded (%d value(s) changed).', count($changed)));

        return Command::SUCCESS;
    }
}
