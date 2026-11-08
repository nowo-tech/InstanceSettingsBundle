<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Command;

use Nowo\InstanceSettingsBundle\File\SettingFileStoreInterface;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * `nowo:instance-settings:purge-files`: deletes the image files no setting references any more, once they have been
 * orphaned for `files.orphan_retention` days (spec 220 §5.10).
 *
 * 1. Marks as orphaned (from now) every stored image that no live setting row references: rows of image fields that
 *    still exist in code only (a field removed from code orphans its images, a row deleted outside the bundle too).
 * 2. Deletes the images (and variants) orphaned at least `orphan_retention` days ago.
 *
 * `--dry-run` reports both counts and changes nothing. Safe to schedule (Scheduler / cron), idempotent.
 */
#[AsCommand(name: self::NAME, description: 'Delete image setting files that are no longer referenced, after the retention period.')]
final class PurgeFilesCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:instance-settings:purge-files';

    /**
     * @param int $retentionDays `files.orphan_retention`
     */
    public function __construct(
        private readonly SettingsSectionRegistry $registry,
        private readonly SettingValueRepositoryInterface $repository,
        private readonly SettingFileStoreInterface $store,
        private readonly int $retentionDays = 30,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    /**
     * Option `--dry-run`.
     */
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be marked and deleted; change nothing.');
    }

    /**
     * Marks the unreferenced images, then deletes the expired ones.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $now    = $this->clock?->now() ?? (new NativeClock())->now();

        $referenced = [];
        foreach ($this->repository->findAll() as $row) {
            $section = $row->getSection();
            $field   = $this->registry->has($section) ? $this->registry->fields($section)[$row->getName()] ?? null : null;
            $sha     = InstanceSettings::imageHash($row->getValue());
            if ($field?->type === SettingType::Image && $sha !== null) {
                $referenced[] = $section . '.' . $row->getName() . '|' . $sha;
            }
        }

        $marked  = $this->store->markUnreferenced($referenced, $now, $dryRun);
        $deleted = $this->store->purge($now->modify(sprintf('-%d days', $this->retentionDays)), $dryRun);

        $this->logger?->info('Instance settings files purged.', [
            'bundle'  => 'NowoInstanceSettingsBundle',
            'marked'  => $marked,
            'deleted' => $deleted,
            'dry_run' => $dryRun,
        ]);
        $io->success(sprintf('%s %d newly unreferenced image(s); %s %d image(s) orphaned for %d day(s) or more.', $dryRun ? 'Would mark' : 'Marked', $marked, $dryRun ? 'would delete' : 'deleted', $deleted, $this->retentionDays));

        return Command::SUCCESS;
    }
}
