<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Command;

use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;
use Nowo\InstanceSettingsBundle\Seed\FallbackSeeder;
use Nowo\InstanceSettingsBundle\Seed\SeedReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function sprintf;

/**
 * `nowo:instance-settings:seed-from-fallbacks [--section=…] [--dry-run]`: copies the section fallbacks
 * (bundle configuration / environment) into the store for the fields that have no stored value. Idempotent; prints
 * `<section>.<field>` keys and reason codes only, never a value.
 */
#[AsCommand(name: self::NAME, description: 'Copy the section fallbacks (env / YAML) into the instance settings store where nothing is stored yet.')]
final class SeedFromFallbacksCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:instance-settings:seed-from-fallbacks';

    /**
     * @param FallbackSeeder $seeder seeding service
     */
    public function __construct(
        private readonly FallbackSeeder $seeder,
    ) {
        parent::__construct();
    }

    /** Skip reasons that mean a fallback could not be copied (the others are expected: stored, empty). */
    private const PROBLEMS = [SeedReport::SKIP_INVALID, SeedReport::SKIP_UNKNOWN_FIELD, SeedReport::SKIP_SECRET_MISMATCH, SeedReport::SKIP_DUPLICATE];

    /**
     * Options `--section` (repeatable), `--dry-run` and `--strict`.
     */
    protected function configure(): void
    {
        $this
            ->addOption('section', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these sections (default: every section exposing fallbacks).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be seeded; write nothing.')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit 1 when a fallback was refused (invalid, unknown field or secret mismatch).');
    }

    /**
     * Seeds and prints the seeded / skipped keys (never values); with `--strict`, exits 1 when a fallback was refused.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $dryRun   = (bool) $input->getOption('dry-run');
        $option   = $input->getOption('section');
        $sections = is_array($option) && $option !== [] ? array_values(array_filter($option, is_string(...))) : null;

        try {
            $report = $this->seeder->seed($sections, $dryRun);
        } catch (UnknownSettingException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($report->sections === []) {
            $io->success('No section exposes fallbacks; nothing to seed.');

            return Command::SUCCESS;
        }

        if ($report->seeded !== []) {
            $io->section($dryRun ? 'Would seed' : 'Seeded');
            $io->listing($report->seeded);
        }
        if ($report->skipped !== []) {
            $io->section('Skipped');
            $lines = [];
            foreach ($report->skipped as $key => $reason) {
                $lines[] = sprintf('%s (%s)', $key, $reason);
            }
            $io->listing($lines);
        }

        $problems = 0;
        foreach (self::PROBLEMS as $reason) {
            $problems += $report->skippedCount($reason);
        }
        if ($problems > 0) {
            $io->warning(sprintf('%d fallback(s) could not be copied (see the reasons above): set them in the admin UI.', $problems));
            if ((bool) $input->getOption('strict')) {
                return Command::FAILURE;
            }
        }

        $io->success(match (true) {
            !$report->hasChanges() => 'Nothing to seed: every fallback is empty or already stored.',
            $dryRun                => sprintf('Dry run: %d setting(s) would be seeded; nothing written.', count($report->seeded)),
            default                => sprintf('Seeded %d setting(s) from the fallbacks.', count($report->seeded)),
        });

        return Command::SUCCESS;
    }
}
