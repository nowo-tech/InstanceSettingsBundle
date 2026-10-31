<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Command;

use JsonException;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

use function array_values;
use function is_string;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * `nowo:instance-settings:export`: writes the instance configuration envelope to stdout or a file.
 */
#[AsCommand(name: self::NAME, description: 'Export the instance settings (and portability providers) as a versioned JSON envelope.')]
final class ExportSettingsCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:instance-settings:export';

    /**
     * @param ConfigPortability $portability export service
     * @param Filesystem $filesystem writes the output file (mode 0600)
     */
    public function __construct(
        private readonly ConfigPortability $portability,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        parent::__construct();
    }

    /**
     * Options: `--section` (repeatable), `--include-secrets` (sealed), `--output`.
     */
    protected function configure(): void
    {
        $this
            ->addOption('section', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these sections / providers (repeatable).')
            ->addOption('include-secrets', null, InputOption::VALUE_NONE, 'Add the stored secrets, sealed with this instance key (only an instance with the same key can import them).')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this file (mode 0600) instead of stdout.');
    }

    /**
     * Prints or writes the JSON envelope.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $sections */
        $sections = array_values((array) $input->getOption('section'));

        try {
            $payload = $this->portability->export($sections === [] ? null : $sections, (bool) $input->getOption('include-secrets'));
            $json    = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (PortabilityException|JsonException $e) {
            $io->getErrorStyle()->error('Export refused: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $file = $input->getOption('output');
        if (!is_string($file) || $file === '') {
            $output->writeln($json, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        $this->filesystem->dumpFile($file, $json . "\n");
        $this->filesystem->chmod($file, 0o600);
        $io->getErrorStyle()->success('Instance configuration written to ' . $file . '.');

        return Command::SUCCESS;
    }
}
