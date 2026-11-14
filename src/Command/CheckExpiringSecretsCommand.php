<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Command;

use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_map;
use function count;
use function implode;
use function sprintf;

/**
 * `nowo:instance-settings:check-expiring-secrets` (1.17): announces the expiring secrets that reached one of
 * `expiring_secrets.thresholds`, once per threshold per expiry date ({@see SecretExpiryChecker}). Schedule it daily
 * (cron / Symfony Scheduler); running it more often is harmless. The host's listeners of
 * {@see SecretExpiryThresholdReachedEvent} send the reminders.
 *
 * Prints `section.field`, the date and the threshold only, never a secret. `--dry-run` lists what is due and changes
 * nothing. Exit code 1 when a listener failed (that reminder is retried by the next run).
 */
#[AsCommand(name: self::NAME, description: 'Announce the expiring secrets that reached an expiry threshold (once per threshold and date).')]
final class CheckExpiringSecretsCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:instance-settings:check-expiring-secrets';

    /**
     * Creates the command.
     */
    public function __construct(
        private readonly SecretExpiryChecker $checker,
    ) {
        parent::__construct();
    }

    /**
     * Option `--dry-run`.
     */
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the thresholds due today; record and dispatch nothing.');
    }

    /**
     * Runs the check and lists the announcements.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $report = $this->checker->check((bool) $input->getOption('dry-run'));

        if ($report->announced !== []) {
            $io->table(['Setting', 'Expires on', 'Threshold (days)', 'Days left'], array_map(
                static fn (SecretExpiryThresholdReachedEvent $event): array => [$event->key(), $event->expiresOn, (string) $event->threshold, (string) $event->daysLeft],
                $report->announced,
            ));
        }
        if ($report->failed !== []) {
            $io->error(sprintf('Reminder listener failed for: %s. They are retried on the next run.', implode(', ', $report->failed)));

            return Command::FAILURE;
        }
        $io->success(sprintf('%s %d expiry reminder(s).', $report->dryRun ? 'Due:' : 'Announced', count($report->announced)));

        return Command::SUCCESS;
    }
}
