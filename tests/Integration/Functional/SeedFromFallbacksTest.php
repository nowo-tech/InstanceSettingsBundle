<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Command\SeedFromFallbacksCommand;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Seed\FallbackSeeder;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SeedFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Translation\TranslatableMessage;

use function bin2hex;
use function random_bytes;

/**
 * 1.2 seed from fallbacks in a real kernel: the command and the public seeder service, secrets encrypted at rest,
 * section notices rendered as warning alerts.
 */
final class SeedFromFallbacksTest extends KernelTestCase
{
    public function testCommandSeedsAndEncryptsSecretsAtRest(): void
    {
        $token                             = 'tok-' . bin2hex(random_bytes(12));
        $kernel                            = $this->bootKernel(services: ['relay_section' => SeedFixtureSection::class]);
        $this->section($kernel)->fallbacks = [SettingFallback::plain('host', 'relay.example.test'), SettingFallback::secret('token', $token)];

        $tester = new CommandTester((new Application($kernel))->find(SeedFromFallbacksCommand::NAME));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringNotContainsString($token, $tester->getDisplay());

        $raw = (string) $this->connection($kernel)->fetchOne("SELECT secret_value FROM instance_setting_value WHERE name = 'token'");
        self::assertNotSame('', $raw);
        self::assertStringNotContainsString($token, $raw, 'encrypted at rest');
        $this->settings($kernel)->reset();
        self::assertSame($token, $this->settings($kernel)->getSecret('relay.token'));
        self::assertSame('relay.example.test', $this->settings($kernel)->get('relay.host'));

        $seeder = $kernel->getContainer()->get(FallbackSeeder::class);
        self::assertInstanceOf(FallbackSeeder::class, $seeder, 'public for host upgrade steps / migrations');
        self::assertFalse($seeder->seed()->hasChanges(), 'idempotent');
    }

    public function testNoticesAreRenderedAsWarnings(): void
    {
        $kernel                          = $this->bootKernel(services: ['relay_section' => SeedFixtureSection::class]);
        $this->section($kernel)->notices = ['relay.notice_token', new TranslatableMessage('mailer.label', [], 'app')];

        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/settings/sections/relay');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $notices = $crawler->filter('[data-testid="instance-settings-notice"][role="alert"]');
        self::assertCount(2, $notices);
        self::assertSame('The relay is enabled but its token is empty.', trim($notices->eq(0)->text()));
        self::assertSame('Mailer', trim($notices->eq(1)->text()));

        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        self::assertCount(0, $crawler->filter('[data-testid="instance-settings-notice"]'));
    }

    private function section(InstanceSettingsTestKernel $kernel): SeedFixtureSection
    {
        $section = $kernel->getContainer()->get('relay_section');
        self::assertInstanceOf(SeedFixtureSection::class, $section);

        return $section;
    }
}
