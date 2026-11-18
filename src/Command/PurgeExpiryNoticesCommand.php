<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Command;

use Nowo\InstanceSettingsBundle\Expiry\ExpiryDate;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeStoreInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function ctype_digit;
use function is_string;
use function sprintf;

/**
 * `nowo:instance-settings:purge-expiry-notices` (1.21): deletes the sent-markers of the expiry reminders whose expiry
 * date is more than `expiring_secrets.notify.retention_days` (or `--days`) in the past. The markers hold an opaque
 * recipient id (personal data of the host's users); once a date is that old the 1.17 threshold record already stops
 * it from being announced again. Schedule it daily or weekly next to the check.
 */
#[AsCommand(name: self::NAME, description: 'Delete the expiry reminder sent-markers of dates older than the retention.')]
final class PurgeExpiryNoticesCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:instance-settings:purge-expiry-notices';

    /**
     * @param int $retentionDays `expiring_secrets.notify.retention_days`
     * @param string|null $timezone zone of "today" (`expiring_secrets.timezone`)
     */
    public function __construct(
        private readonly ExpiryNoticeStoreInterface $store,
        private readonly int $retentionDays,
        private readonly ?ClockInterface $clock = null,
        private readonly ?string $timezone = null,
    ) {
        parent::__construct();
    }

    /**
     * Option `--days`.
     */
    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Keep the markers of dates up to this many days ago (default: expiring_secrets.notify.retention_days).');
    }

    /**
     * Deletes the old markers and prints how many.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $days = $input->getOption('days');
        if ($days !== null && (!is_string($days) || !ctype_digit($days))) {
            $io->error('--days must be a whole number of days.');

            return Command::INVALID;
        }
        $keep    = $days === null ? $this->retentionDays : (int) $days;
        $before  = ExpiryDate::today($this->clock ?? new NativeClock(), $this->timezone)->modify(sprintf('-%d days', $keep));
        $deleted = $this->store->purge($before);
        $io->success(sprintf('Deleted %d expiry reminder marker(s) of dates before %s.', $deleted, ExpiryDate::format($before)));

        return Command::SUCCESS;
    }
}
