<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Command;

use Nowo\InstanceSettingsBundle\Seed\SeedFileException;
use Nowo\InstanceSettingsBundle\Seed\SeedFileSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
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
 * `nowo:instance-settings:seed [file] [--section=…] [--dry-run]` (1.7, IS-3): loads the host seed file (default
 * `seed.file`) fill-only — values and images of the fields that are still empty; never overwrites. Validated as a
 * whole first: any refused entry prints the problems (keys and reason codes, never values), writes nothing and exits 1.
 */
#[AsCommand(name: self::NAME, description: 'Load the host seed file (values and images) into the instance settings, filling only empty fields.')]
final class SeedCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:instance-settings:seed';

    /**
     * @param SeedFileSeeder $seeder seed file service
     */
    public function __construct(
        private readonly SeedFileSeeder $seeder,
    ) {
        parent::__construct();
    }

    /**
     * Argument `file` (optional); options `--section` (repeatable) and `--dry-run`.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Seed file (.yaml, .yml or .json); default: nowo_instance_settings.seed.file.')
            ->addOption('section', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these sections of the file.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and report what would be seeded; write nothing.');
    }

    /**
     * Seeds and prints the seeded / skipped keys; exits 1 when the file is refused.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $dryRun   = (bool) $input->getOption('dry-run');
        $option   = $input->getOption('section');
        $sections = is_array($option) && $option !== [] ? array_values(array_filter($option, is_string(...))) : null;
        $file     = $input->getArgument('file');

        try {
            $report = $this->seeder->seedFile(is_string($file) ? $file : null, $sections, $dryRun);
        } catch (SeedFileException $e) {
            $io->error('Seed refused; nothing was written.');
            $io->listing($e->errors);

            return Command::FAILURE;
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

        $io->success(match (true) {
            !$report->hasChanges() => 'Nothing to seed: every field of the seed file is empty or already set.',
            $dryRun                => sprintf('Dry run: %d setting(s) would be seeded; nothing written.', count($report->seeded)),
            default                => sprintf('Seeded %d setting(s) from the seed file.', count($report->seeded)),
        });

        return Command::SUCCESS;
    }
}
