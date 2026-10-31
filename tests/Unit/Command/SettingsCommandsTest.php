<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Command;

use Nowo\InstanceSettingsBundle\Command\ExportSettingsCommand;
use Nowo\InstanceSettingsBundle\Command\ImportSettingsCommand;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\JsonUploadReader;
use Nowo\InstanceSettingsBundle\Portability\PortabilityProviderInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\SealerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use const JSON_THROW_ON_ERROR;

final class SettingsCommandsTest extends TestCase
{
    private string $dir;

    private InMemorySettingValueRepository $repository;

    private InstanceSettings $settings;

    private ConfigPortability $portability;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-instance-settings-cli-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $registry          = new SettingsSectionRegistry([new MailerFixtureSection(), new GeneralFixtureSection()]);
        $this->repository  = new InMemorySettingValueRepository();
        $this->settings    = new InstanceSettings($this->repository, $registry);
        $this->portability = new ConfigPortability($registry, $this->settings, $this->repository, SealerFactory::create());
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testExportToStdoutAndFile(): void
    {
        $this->settings->save('mailer', ['from' => 'ops@example.test', 'dsn' => 'smtp://REDACTED-TEST']);
        $tester = new CommandTester(new ExportSettingsCommand($this->portability));

        self::assertSame(Command::SUCCESS, $tester->execute(['--section' => ['mailer']]));
        $payload = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['mailer'], array_keys($payload['settings']));
        self::assertArrayNotHasKey('secrets', $payload);

        $file = $this->dir . '/export.json';
        self::assertSame(Command::SUCCESS, $tester->execute(['--include-secrets' => true, '--output' => $file]));
        self::assertSame(0o600, fileperms($file) & 0o777);
        $content = (string) file_get_contents($file);
        self::assertStringContainsString('enc:v1:', $content);
        self::assertStringNotContainsString('REDACTED', $content);

        self::assertSame(Command::FAILURE, $tester->execute(['--section' => ['nope']]));
        self::assertStringContainsString('unknown_section:nope', $tester->getDisplay());
    }

    public function testImportDryRunAndApply(): void
    {
        $file = $this->dir . '/import.json';
        file_put_contents($file, json_encode(['schema' => 'nowo-instance-settings', 'version' => 1, 'settings' => ['general' => ['page_size' => 42]]]));
        $tester = new CommandTester(new ImportSettingsCommand($this->portability, new JsonUploadReader()));

        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $file, '--dry-run' => true]));
        self::assertStringContainsString('general.page_size', $tester->getDisplay());
        self::assertStringContainsString('dry run', $tester->getDisplay());
        self::assertSame(20, $this->settings->getInt('general.page_size'));

        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $file]));
        self::assertStringContainsString('Imported', $tester->getDisplay());
        self::assertSame(42, $this->settings->getInt('general.page_size'));

        $empty = $this->dir . '/empty.json';
        file_put_contents($empty, json_encode(['schema' => 'nowo-instance-settings', 'version' => 1, 'providers' => []]));
        self::assertSame(Command::FAILURE, $tester->execute(['file' => $empty]));
        self::assertStringContainsString('empty_payload', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['file' => $this->dir . '/missing.json']));
        self::assertStringContainsString('missing_file', $tester->getDisplay());
    }

    public function testImportWithoutChangedKeys(): void
    {
        $provider = new class implements PortabilityProviderInterface {
            public function getName(): string
            {
                return 'flags';
            }

            public function export(): array
            {
                return [];
            }

            public function validate(array $data): void
            {
            }

            public function import(array $data): array
            {
                return [];
            }
        };
        $registry    = new SettingsSectionRegistry([new MailerFixtureSection()]);
        $portability = new ConfigPortability($registry, $this->settings, $this->repository, null, [$provider]);
        $file        = $this->dir . '/noop.json';
        file_put_contents($file, json_encode(['schema' => 'nowo-instance-settings', 'version' => 1, 'providers' => ['flags' => ['x' => 1]]]));
        $tester = new CommandTester(new ImportSettingsCommand($portability, new JsonUploadReader()));

        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $file]));
        self::assertStringContainsString('Nothing changed', $tester->getDisplay());

        file_put_contents($file, json_encode(['schema' => 'nowo-instance-settings', 'version' => 1, 'providers' => ['flags' => []]]));
        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $file, '--dry-run' => true]));
        self::assertStringContainsString('nothing would change', $tester->getDisplay());
    }
}
