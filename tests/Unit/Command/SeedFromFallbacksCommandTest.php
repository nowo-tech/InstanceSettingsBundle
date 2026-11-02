<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Command;

use Nowo\InstanceSettingsBundle\Command\SeedFromFallbacksCommand;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Seed\FallbackSeeder;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SeedFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function bin2hex;
use function random_bytes;

final class SeedFromFallbacksCommandTest extends TestCase
{
    private InMemorySettingValueRepository $repository;

    private InstanceSettings $settings;

    private string $token;

    private SeedFixtureSection $section;

    protected function setUp(): void
    {
        $this->token   = 'tok-' . bin2hex(random_bytes(12));
        $this->section = new SeedFixtureSection([SettingFallback::plain('host', 'relay.example.test'), SettingFallback::secret('token', $this->token)]);
    }

    public function testSeedsPrintsNamesOnlyAndIsIdempotent(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('relay.host', $display);
        self::assertStringContainsString('relay.token', $display);
        self::assertStringContainsString('Seeded 2 setting(s) from the fallbacks.', $display);
        self::assertStringNotContainsString($this->token, $display, 'a secret is never printed');
        self::assertStringNotContainsString('relay.example.test', $display, 'no value is printed');
        self::assertSame($this->token, $this->settings->getSecret('relay.token'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('relay.token (stored)', $tester->getDisplay());
        self::assertStringContainsString('Nothing to seed', $tester->getDisplay());
    }

    public function testDryRunWritesNothing(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true, '--section' => ['relay']]));
        self::assertStringContainsString('Would seed', $tester->getDisplay());
        self::assertStringContainsString('Dry run: 2 setting(s) would be seeded; nothing written.', $tester->getDisplay());
        self::assertSame([], $this->repository->rows);
    }

    public function testStrictFailsOnRefusedFallbacks(): void
    {
        $this->section->fallbacks = [SettingFallback::plain('port', 'not-a-number')];
        $tester                   = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('1 fallback(s) could not be copied', $tester->getDisplay());
        self::assertStringNotContainsString('not-a-number', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['--strict' => true]));
    }

    public function testUnknownSectionAndNoProvider(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::FAILURE, $tester->execute(['--section' => ['nope']]));

        self::assertSame(Command::SUCCESS, $tester->execute(['--section' => ['mailer']]));
        self::assertStringContainsString('No section exposes fallbacks; nothing to seed.', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $registry         = new SettingsSectionRegistry([new MailerFixtureSection(), $this->section]);
        $this->repository = new InMemorySettingValueRepository();
        $this->settings   = new InstanceSettings($this->repository, $registry);

        return new CommandTester(new SeedFromFallbacksCommand(new FallbackSeeder($registry, $this->repository, $this->settings)));
    }
}
