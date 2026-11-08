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
 * 1. Reconciles the orphan marks with the live setting rows: an image referenced again loses its mark, and every
 *    stored image that no live row references is marked as orphaned (from now). A reference is a row holding the
 *    image's `isf:` hash for an image field that still exists in code (a field removed from code orphans its images,
 *    a row deleted outside the bundle too). Rows of a section the registry does not know (a section behind a feature
 *    flag, a broken deploy) keep their images unless `--include-unknown-sections` is given.
 * 2. Deletes the images (and variants) orphaned at least `orphan_retention` days ago, re-reading the setting rows
 *    inside the store's delete transaction: an image that is referenced at that moment is never deleted.
 *
 * `--dry-run` reports both counts and changes nothing. Safe to schedule (Scheduler / cron), idempotent, self-healing.
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
        $this->addOption('include-unknown-sections', null, InputOption::VALUE_NONE, 'Also treat the images of sections that are not registered (any more) as unreferenced.');
    }

    /**
     * Marks the unreferenced images, then deletes the expired ones.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io             = new SymfonyStyle($input, $output);
        $dryRun         = (bool) $input->getOption('dry-run');
        $includeUnknown = (bool) $input->getOption('include-unknown-sections');
        $now            = $this->clock?->now() ?? (new NativeClock())->now();
        $referenced     = fn (): array => $this->references($includeUnknown);

        $marked  = $this->store->markUnreferenced($referenced(), $now, $dryRun);
        $deleted = $this->store->purge($now->modify(sprintf('-%d days', $this->retentionDays)), $referenced, $dryRun);

        $this->logger?->info('Instance settings files purged.', [
            'bundle'  => 'NowoInstanceSettingsBundle',
            'marked'  => $marked,
            'deleted' => $deleted,
            'dry_run' => $dryRun,
        ]);
        $io->success(sprintf('%s %d newly unreferenced image(s); %s %d image(s) orphaned for %d day(s) or more.', $dryRun ? 'Would mark' : 'Marked', $marked, $dryRun ? 'would delete' : 'deleted', $deleted, $this->retentionDays));

        return Command::SUCCESS;
    }

    /**
     * `<section>.<field>|<sha256>` of every live image reference, read from the setting rows now.
     *
     * @return list<string>
     */
    private function references(bool $includeUnknown): array
    {
        $referenced = [];
        foreach ($this->repository->findAll() as $row) {
            $section = $row->getSection();
            $sha     = InstanceSettings::imageHash($row->getValue());
            if ($sha === null) {
                continue;
            }
            $live = $this->registry->has($section)
                ? ($this->registry->fields($section)[$row->getName()] ?? null)?->type === SettingType::Image
                : !$includeUnknown;
            if ($live) {
                $referenced[] = $section . '.' . $row->getName() . '|' . $sha;
            }
        }

        return $referenced;
    }
}
