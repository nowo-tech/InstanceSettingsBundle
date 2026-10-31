<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Command;

use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\JsonUploadReader;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `nowo:instance-settings:import <file>`: validates and applies an export envelope (all-or-nothing).
 */
#[AsCommand(name: self::NAME, description: 'Import an instance settings JSON envelope (validated first, applied all-or-nothing).')]
final class ImportSettingsCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:instance-settings:import';

    /**
     * @param ConfigPortability $portability import service
     * @param JsonUploadReader $reader size-capped JSON reader
     */
    public function __construct(
        private readonly ConfigPortability $portability,
        private readonly JsonUploadReader $reader,
    ) {
        parent::__construct();
    }

    /**
     * Argument `file`; option `--dry-run`.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path of the JSON envelope.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate only; print what would be applied.');
    }

    /**
     * Imports and prints the changed keys.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $payload = $this->reader->fromPath((string) $input->getArgument('file'));
            $result  = $this->portability->import($payload, $dryRun);
        } catch (PortabilityException $e) {
            $io->error('Import refused: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $keys = $result->changedKeys();
        if ($keys === []) {
            $io->success($dryRun ? 'Valid file; nothing would change.' : 'Nothing changed.');

            return Command::SUCCESS;
        }

        $io->listing($keys);
        $io->success($dryRun ? 'Valid file; the keys above would be applied (dry run, nothing written).' : 'Imported; the keys above changed.');

        return Command::SUCCESS;
    }
}
