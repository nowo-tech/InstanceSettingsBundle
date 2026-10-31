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

use function array_values;
use function bin2hex;
use function chmod;
use function dirname;
use function file_exists;
use function file_put_contents;
use function is_file;
use function is_link;
use function is_string;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rename;
use function rmdir;
use function strlen;
use function unlink;

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
     */
    public function __construct(
        private readonly ConfigPortability $portability,
    ) {
        parent::__construct();
    }

    /**
     * Options: `--section` (repeatable), `--include-secrets` (sealed), `--output`, `--force`.
     */
    protected function configure(): void
    {
        $this
            ->addOption('section', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these sections / providers (repeatable).')
            ->addOption('include-secrets', null, InputOption::VALUE_NONE, 'Add the stored secrets, sealed with this instance key (only an instance with the same key can import them).')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to this new file (created with mode 0600) instead of stdout.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Replace an existing regular file (never a symbolic link).');
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

        $error = self::writePrivateFile($file, $json . "\n", (bool) $input->getOption('force'));
        if ($error !== null) {
            $io->getErrorStyle()->error('Export not written: ' . $error);

            return Command::FAILURE;
        }
        $io->getErrorStyle()->success('Instance configuration written to ' . $file . '.');

        return Command::SUCCESS;
    }

    /**
     * Writes `$content` to `$path` with mode 0600 without the content ever being readable by others: the file is
     * written inside a fresh 0700 directory next to the target, set to 0600, then renamed into place (atomic; the
     * rename replaces the directory entry and never follows a link). Symbolic links are refused; an existing regular
     * file only with `$force`.
     *
     * @return string|null error message, or null on success
     */
    public static function writePrivateFile(string $path, string $content, bool $force = false): ?string
    {
        if (is_link($path)) {
            return 'the output path is a symbolic link.';
        }
        if (file_exists($path) && (!$force || !is_file($path))) {
            return 'the output file already exists (use --force to replace a regular file).';
        }

        $dir = dirname($path) . '/.nowo-instance-settings-' . bin2hex(random_bytes(8));
        if (!@mkdir($dir, 0o700)) {
            return 'the output file cannot be created.';
        }

        $tmp = $dir . '/export.json';
        try {
            $written = file_put_contents($tmp, $content) === strlen($content) && chmod($tmp, 0o600) && rename($tmp, $path);
        } finally {
            // Left behind only when a step failed.
            @unlink($tmp);
            rmdir($dir);
        }

        return $written ? null : 'the output file could not be written.';
    }
}
