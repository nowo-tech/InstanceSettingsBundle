<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Command;

use Nowo\InstanceSettingsBundle\Command\SeedCommand;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Seed\SeedFileSeeder;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SeedFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

final class SeedCommandTest extends TestCase
{
    private string $dir;

    private InMemorySettingValueRepository $repository;

    private InstanceSettings $settings;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-is-seed-cmd-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
        file_put_contents($this->dir . '/seed.yaml', "schema: nowo-instance-settings-seed\nversion: 1\nsettings:\n  relay:\n    host: relay.example.test\n    port: 2525\n    enabled: ''\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testSeedsTheConfiguredFileAndIsIdempotent(): void
    {
        $tester = $this->tester($this->dir . '/seed.yaml');

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertStringContainsString('Would seed', $tester->getDisplay());
        self::assertStringContainsString('Dry run: 2 setting(s) would be seeded; nothing written.', $tester->getDisplay());
        self::assertSame([], $this->repository->rows);

        self::assertSame(Command::SUCCESS, $tester->execute(['--section' => ['relay']]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('relay.host', $display);
        self::assertStringContainsString('relay.enabled (empty)', $display);
        self::assertStringContainsString('Seeded 2 setting(s) from the seed file.', $display);
        self::assertStringNotContainsString('relay.example.test', $display, 'no value is printed');
        self::assertSame('relay.example.test', $this->settings->get('relay.host'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('relay.host (stored)', $tester->getDisplay());
        self::assertStringContainsString('Nothing to seed', $tester->getDisplay());
    }

    public function testARefusedFileExitsOneAndWritesNothing(): void
    {
        file_put_contents($this->dir . '/bad.yaml', "schema: nowo-instance-settings-seed\nversion: 1\nsettings:\n  relay:\n    host: ok.example.test\n    token: tok-secret-1\n");
        $tester = $this->tester(null);

        self::assertSame(Command::FAILURE, $tester->execute(['file' => $this->dir . '/bad.yaml']));
        self::assertStringContainsString('Seed refused; nothing was written.', $tester->getDisplay());
        self::assertStringContainsString('relay.token: secret_field', $tester->getDisplay());
        self::assertStringNotContainsString('tok-secret-1', $tester->getDisplay());
        self::assertSame([], $this->repository->rows);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('file: no_file', $tester->getDisplay());
    }

    private function tester(?string $defaultFile): CommandTester
    {
        $this->repository = new InMemorySettingValueRepository();
        $registry         = new SettingsSectionRegistry([new SeedFixtureSection()]);
        $this->settings   = new InstanceSettings($this->repository, $registry);

        return new CommandTester(new SeedCommand(new SeedFileSeeder($registry, $this->repository, $this->settings, $this->dir, $defaultFile)));
    }
}
