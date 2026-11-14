<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\InstanceSettingsBundle\Command\CheckExpiringSecretsCommand;
use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Nowo\InstanceSettingsBundle\Expiry\DoctrineSecretExpiryStore;
use Nowo\InstanceSettingsBundle\Expiry\ExpiringSecret;
use Nowo\InstanceSettingsBundle\Expiry\ExpiringSecretSourceInterface;
use Nowo\InstanceSettingsBundle\Expiry\ExpiryDate;
use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryStoreInterface;
use Nowo\InstanceSettingsBundle\ExpiryEntity\SecretExpiry;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ExpiringFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GroupedExpiringFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_keys;
use function array_map;

/**
 * 1.17 expiring secrets on a real store (SQLite): the standard host and the DDD host (dedicated entity manager, XML
 * domain), the table prefix, a kept-alive worker without kernel reset, the admin page (date row, warnings, escaping,
 * the save rule) and the feature switched off.
 */
final class ExpiringSecretsTest extends KernelTestCase
{
    private const SECRET = 'sk-live-INTEGRATION-SECRET';

    private const ENABLED = ['expiring_secrets' => ['enabled' => true, 'timezone' => 'UTC']];

    /**
     * The same switch for the HTTP tests: another container (class) than the console tests of this class, whose
     * lazy service classes would otherwise be looked up in a cache directory already removed.
     */
    private const ENABLED_UI = ['expiring_secrets' => ['enabled' => true, 'timezone' => 'Etc/UTC']];

    public function testStandardHostAnnouncesEachThresholdOnceAcrossWorkerResets(): void
    {
        $kernel = $this->bootKernel(self::ENABLED, ['providers_section' => ExpiringFixtureSection::class]);
        $this->assertOncePerThreshold($kernel);
    }

    public function testDddHostWithADedicatedManagerKeepsTheTableOffTheDomainManager(): void
    {
        $kernel   = $this->bootKernel(self::ENABLED + ['doctrine' => ['entity_manager' => 'settings']], ['providers_section' => ExpiringFixtureSection::class], extensions: ['doctrine' => $this->twoManagers()]);
        $registry = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $host     = $registry->getManager('default');
        $settings = $registry->getManager('settings');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertInstanceOf(EntityManagerInterface::class, $settings);
        self::assertTrue($host->getMetadataFactory()->isTransient(SecretExpiry::class), 'the expiry entity is not on the domain manager');
        self::assertSame($settings, $registry->getManagerForClass(SecretExpiry::class));
        (new SchemaTool($settings))->createSchema($settings->getMetadataFactory()->getAllMetadata());

        $this->assertOncePerThreshold($kernel);
        self::assertSame(1, (int) $settings->getConnection()->fetchOne('SELECT COUNT(*) FROM instance_setting_secret_expiry'));
        self::assertNotContains('instance_setting_secret_expiry', $host->getConnection()->createSchemaManager()->listTableNames(), 'nothing on the domain database');
    }

    public function testTheDoctrineStoreHonoursTheTablePrefixAndMovesThresholdsAtomically(): void
    {
        $kernel = $this->bootKernel(self::ENABLED + ['doctrine' => ['table_prefix' => 'app_']], ['providers_section' => ExpiringFixtureSection::class]);
        $store  = $this->container($kernel)->get(SecretExpiryStoreInterface::class);
        self::assertInstanceOf(DoctrineSecretExpiryStore::class, $store);
        $at   = new DateTimeImmutable('2026-11-14 10:00:00');
        $date = new DateTimeImmutable('2027-01-31');

        $store->write('providers', [], $at);
        $store->write('providers', ['api_key' => $date, 'backup_key' => new DateTimeImmutable('2027-02-01'), 'webhook_token' => null], $at);
        self::assertSame(2, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM app_instance_setting_secret_expiry'));
        self::assertSame(['api_key', 'backup_key'], array_keys($store->findBySection('providers')));
        self::assertSame([], $store->findBySection('general'));

        self::assertTrue($store->claim('providers', 'api_key', $date, 30, $at));
        self::assertFalse($store->claim('providers', 'api_key', $date, 30, $at), 'the same threshold twice: refused');
        self::assertFalse($store->claim('providers', 'api_key', new DateTimeImmutable('2027-02-28'), 7, $at), 'another date: refused');
        self::assertTrue($store->claim('providers', 'api_key', $date, 7, $at));
        self::assertFalse($store->claim('providers', 'api_key', $date, 30, $at), 'a larger threshold after a smaller one: refused');
        $store->release('providers', 'api_key', $date, 7, 30);
        self::assertSame(30, $store->findAll()[0]->notifiedThreshold);
        $store->release('providers', 'api_key', $date, 7, null);
        self::assertSame(30, $store->findAll()[0]->notifiedThreshold, 'a release that does not match the current claim does nothing');
        $store->release('providers', 'api_key', $date, 30, null);
        self::assertNull($store->findAll()[0]->notifiedThreshold);
        self::assertNull($this->connection($kernel)->fetchOne("SELECT notified_at FROM app_instance_setting_secret_expiry WHERE name = 'api_key'"));

        $store->claim('providers', 'api_key', $date, 0, $at);
        $store->write('providers', ['api_key' => $date], $at);
        self::assertSame(0, $store->findAll()[0]->notifiedThreshold, 'the same date keeps the record');
        $store->write('providers', ['api_key' => new DateTimeImmutable('2028-01-31')], $at);
        self::assertNull($store->findAll()[0]->notifiedThreshold, 'a new date resets the record');
        self::assertSame('2028-01-31', ExpiryDate::format($store->findAll()[0]->expiresOn));

        $store->write('providers', ['api_key' => null, 'backup_key' => null], $at);
        self::assertSame([], $store->findAll());

        $entity = new SecretExpiry('providers', 'api_key', $date, $at);
        self::assertSame([null, 'providers', 'api_key', $date, $at, null, null], [$entity->getId(), $entity->getSection(), $entity->getName(), $entity->getExpiresOn(), $entity->getUpdatedAt(), $entity->getNotifiedThreshold(), $entity->getNotifiedAt()]);
    }

    public function testTheAdminPageShowsTheDateRowAndEscapedWarnings(): void
    {
        $kernel   = $this->bootKernel(self::ENABLED_UI, ['providers_section' => ExpiringFixtureSection::class]);
        $settings = $this->settings($kernel);
        $soon     = $this->today()->modify('+5 days');
        $expired  = $this->today()->modify('-2 days');
        $settings->save('providers', ['mode' => 'backup', 'api_key' => self::SECRET, 'api_key:expires_on' => $soon, 'backup_key' => 'bk-INTEGRATION-SECRET', 'backup_key:expires_on' => $expired]);
        $settings->reset();

        $client = $this->client($kernel);
        $client->request('GET', '/admin/settings/sections/providers');
        $this->assertResponseIsOk($client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('name="settings_section[api_key:expires_on]"', $html);
        self::assertStringContainsString('value="' . ExpiryDate::format($soon) . '"', $html, 'the stored date prefills the row');
        self::assertStringContainsString('data-nowo-instance-settings-expiry="expiring"', $html);
        self::assertStringContainsString('data-nowo-instance-settings-expiry="expired"', $html);
        self::assertStringContainsString('data-nowo-instance-settings-expiry-for="api_key"', $html);
        self::assertStringNotContainsString('name="settings_section[webhook_token:expires_on]"', $html, 'a secret without expires has no date row');
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; API key” expires on ' . ExpiryDate::format($soon) . ' (in 5 day(s)).', $html, 'the notice names the field, escaped');
        self::assertStringContainsString('expired on ' . ExpiryDate::format($expired) . ' (2 day(s) ago)', $html);
        self::assertStringContainsString('Expires on ' . ExpiryDate::format($soon) . ' (in 5 day(s)).', $html, 'the warning next to the field');
        self::assertStringNotContainsString('<img src=x', $html, 'nothing renders the label raw');
        self::assertStringNotContainsString(self::SECRET, $html);
        self::assertStringNotContainsString('bk-INTEGRATION-SECRET', $html);

        // A section without expiring secrets renders as before (no date row, no warning).
        $client->request('GET', '/admin/settings/sections/general');
        $this->assertResponseIsOk($client->getResponse()->getStatusCode());
        self::assertStringNotContainsString(':expires_on', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('data-nowo-instance-settings-expiry', (string) $client->getResponse()->getContent());
    }

    public function testTheAdminFormAppliesTheSaveRule(): void
    {
        $kernel   = $this->bootKernel(self::ENABLED_UI, ['providers_section' => ExpiringFixtureSection::class]);
        $settings = $this->settings($kernel);
        $client   = $this->client($kernel);
        $form     = static fn (): Form => $client->request('GET', '/admin/settings/sections/providers')->filter('form[name="settings_section"]')->form();

        // A new secret with a date stores both.
        $client->submit($form(), ['settings_section[api_key][value]' => self::SECRET, 'settings_section[api_key:expires_on]' => '2031-01-31']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('2031-01-31', $this->dateOf($kernel, 'api_key'));

        // The date alone (secret left blank = kept).
        $client->submit($form(), ['settings_section[api_key:expires_on]' => '2032-02-29']);
        self::assertSame('2032-02-29', $this->dateOf($kernel, 'api_key'));
        $settings->reset();
        self::assertSame(self::SECRET, $settings->getSecret('providers.api_key'));

        // An untouched form changes nothing.
        $client->submit($form());
        self::assertSame('2032-02-29', $this->dateOf($kernel, 'api_key'));

        // A new secret with the date left as it was clears it.
        $client->submit($form(), ['settings_section[api_key][value]' => 'sk-rotated']);
        self::assertNull($this->dateOf($kernel, 'api_key'));

        // A date for a field without a stored secret is refused next to the date row.
        $client->submit($form(), ['settings_section[backup_key:expires_on]' => '2031-01-31', 'settings_section[mode]' => 'backup']);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Save the secret before its expiry date.', (string) $client->getResponse()->getContent());

        // A provider-dependent row hidden for the submitted provider keeps its date, whatever was typed.
        $settings->save('providers', ['mode' => 'backup', 'backup_key' => 'bk-1', 'backup_key:expires_on' => '2031-03-01']);
        $client->submit($form(), ['settings_section[mode]' => 'main', 'settings_section[backup_key:expires_on]' => '2035-01-01']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('2031-03-01', $this->dateOf($kernel, 'backup_key'));
    }

    public function testTheGroupedTabsLayoutKeepsTheDateWithItsSecret(): void
    {
        $kernel  = $this->bootKernel(self::ENABLED_UI + ['web_ui' => ['section_layout' => 'tabs']], ['providers_section' => GroupedExpiringFixtureSection::class]);
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/settings/sections/providers');
        $panel   = $crawler->filter('[data-nowo-instance-settings-group-panel="keys"]');
        self::assertCount(1, $panel->filter('[name="settings_section[api_key:expires_on]"]'), 'the date row sits in its secret\'s subtab');

        $client->submit($crawler->filter('form[name="settings_section"]')->form(), ['settings_section[api_key:expires_on]' => '2031-01-31']);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-invalid', (string) $client->getResponse()->getContent(), 'the error marks the subtab');
    }

    public function testOffByDefault(): void
    {
        $kernel   = $this->bootKernel();
        $doctrine = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $manager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertTrue($manager->getMetadataFactory()->isTransient(SecretExpiry::class), 'no expiry table without the flag');
        self::assertFalse($this->container($kernel)->has(SecretExpiryStoreInterface::class));
        self::assertFalse($this->container($kernel)->has(ExpiringSecretSourceInterface::class));
        self::assertFalse((new Application($kernel))->has(CheckExpiringSecretsCommand::NAME));

        $kernel   = $this->bootKernel(services: ['providers_section' => ExpiringFixtureSection::class]);
        $registry = $this->container($kernel)->get(SettingsSectionRegistry::class);
        self::assertInstanceOf(SettingsSectionRegistry::class, $registry);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('expiring_secrets.enabled: true');
        $registry->fields('providers');
    }

    private function assertOncePerThreshold(InstanceSettingsTestKernel $kernel): void
    {
        $events     = [];
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(SecretExpiryThresholdReachedEvent::class, static function (SecretExpiryThresholdReachedEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $settings = $this->settings($kernel);
        $settings->save('providers', ['api_key' => self::SECRET, 'api_key:expires_on' => $this->today()->modify('+5 days')]);

        $tester = new CommandTester((new Application($kernel))->find(CheckExpiringSecretsCommand::NAME));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('providers.api_key', $tester->getDisplay());
        self::assertStringNotContainsString(self::SECRET, $tester->getDisplay());
        self::assertSame([7], array_map(static fn (SecretExpiryThresholdReachedEvent $event): int => $event->threshold, $events));

        // Same worker, again: nothing. A services reset (or a restarted worker) changes nothing: the state is stored.
        $tester->execute([]);
        $resetter = $this->container($kernel)->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();
        $tester->execute([]);
        self::assertCount(1, $events);

        // A new date (renewed key) is announced again.
        $settings->save('providers', ['api_key:expires_on' => $this->today()->modify('+20 days')]);
        $tester->execute([]);
        self::assertSame([7, 30], array_map(static fn (SecretExpiryThresholdReachedEvent $event): int => $event->threshold, $events));

        $source = $this->container($kernel)->get(ExpiringSecretSourceInterface::class);
        self::assertInstanceOf(ExpiringSecretSourceInterface::class, $source);
        self::assertSame(['providers.api_key'], array_map(static fn (ExpiringSecret $secret): string => $secret->key(), $source->expiringWithin(30, $this->today())));
    }

    private function dateOf(InstanceSettingsTestKernel $kernel, string $field): ?string
    {
        $store = $this->container($kernel)->get(SecretExpiryStoreInterface::class);
        self::assertInstanceOf(SecretExpiryStoreInterface::class, $store);
        $record = $store->findBySection('providers')[$field] ?? null;

        return $record === null ? null : ExpiryDate::format($record->expiresOn);
    }

    private function today(): DateTimeImmutable
    {
        return ExpiryDate::of(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    private function assertResponseIsOk(int $status): void
    {
        self::assertSame(200, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function twoManagers(): array
    {
        return [
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => ['settings' => ['url' => 'sqlite:///:memory:']],
            ],
            'orm' => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default' => ['connection' => 'default', 'auto_mapping' => true, 'mappings' => ['HostXml' => [
                        'type'      => 'xml',
                        'dir'       => '%kernel.project_dir%/tests/Fixtures/HostXml/mapping',
                        'prefix'    => 'Nowo\InstanceSettingsBundle\Tests\Fixtures\HostXml',
                        'is_bundle' => false,
                    ]]],
                    'settings' => ['connection' => 'settings'],
                ],
            ],
        ];
    }
}
