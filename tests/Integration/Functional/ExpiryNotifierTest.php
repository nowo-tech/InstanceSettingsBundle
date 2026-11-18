<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Command\CheckExpiringSecretsCommand;
use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Nowo\InstanceSettingsBundle\Expiry\ExpiryDate;
use Nowo\InstanceSettingsBundle\Expiry\Notify\DoctrineExpiryNoticeStore;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeKey;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeStoreInterface;
use Nowo\InstanceSettingsBundle\Expiry\Notify\SecretExpiryNotifier;
use Nowo\InstanceSettingsBundle\ExpiryNoticeEntity\SecretExpiryNotice;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ExpiringFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ExpiryRecipientsFixture;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\NotifyKitBundle\NowoNotifyKitBundle;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Service\ResetInterface;

use function array_map;
use function array_values;
use function count;
use function json_encode;
use function sys_get_temp_dir;
use function uniqid;

/**
 * 1.21 (KIT-88): the default expiry notifier in a real kernel — the mail channel and the NotifyKit bell from the daily
 * command, each threshold once per recipient (also across a services reset and two "servers" on one database), the
 * secret never in a mail or a notification, the standard and the DDD host, the table prefix and the feature off.
 */
final class ExpiryNotifierTest extends KernelTestCase
{
    private const SECRET = 'sk-live-KIT88-NEVER-MAILED';

    private const NOTIFY = [
        'enabled'            => true,
        'roles'              => ['ROLE_DEVELOPER', 'ROLE_MANAGER'],
        'recipient_provider' => ExpiryRecipientsFixture::class,
        'channels'           => ['mail' => 'on'],
        // No MailKit in these kernels: the sender is configured (MailKit would fill it from its settings).
        'mail_from' => 'Settings <noreply@settings.example.test>',
    ];

    public function testEveryThresholdMailsAndRingsOncePerRecipientAndNeverLeaksTheSecret(): void
    {
        $kernel   = $this->boot(['expiring_secrets' => ['enabled' => true, 'timezone' => 'UTC', 'notify' => self::NOTIFY]], notifyKit: true);
        $settings = $this->settings($kernel);
        $tester   = new CommandTester((new Application($kernel))->find(CheckExpiringSecretsCommand::NAME));
        $sent     = [];

        foreach (['+30 days' => 30, '+7 days' => 7, '+1 day' => 1, 'today' => 0, '-2 days' => 0] as $date => $threshold) {
            $settings->save('providers', ['api_key' => self::SECRET, 'api_key:expires_on' => $this->today()->modify($date)]);
            $settings->reset();
            self::assertSame(0, $tester->execute([]), $date);
            $mails = $this->mails($kernel);
            self::assertCount(2, $mails, $date . ': the developer and the manager (the third person has no address)');
            self::assertSame(['dev@example.test', 'manager@example.test'], array_map(static fn (Email $email): string => $email->getTo()[0]->getAddress(), $mails));
            foreach ($mails as $mail) {
                $whole = $mail->toString();
                self::assertStringNotContainsString(self::SECRET, $whole, 'the secret is never in a mail');
                self::assertStringNotContainsString(self::SECRET, $mail->getHtmlBody() . $mail->getTextBody());
                self::assertStringNotContainsString('sk-live', $whole, 'not even a part of it');
            }
            $sent[] = [$threshold, (string) $mails[0]->getSubject()];

            // Again on the same day, and after a services reset (a kept-alive worker): nothing more.
            $tester->execute([]);
            $this->resetter($kernel)->reset();
            $tester->execute([]);
            self::assertSame([], $this->mails($kernel), $date . ' (again)');
        }

        $html = (string) $this->lastDeveloperMail;
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; API key', $html, 'the label is escaped');
        self::assertStringContainsString('href="https://settings.example.test/admin/settings/sections/providers"', $html);
        self::assertStringContainsString('expired on', $html);
        self::assertSame([30, 7, 1, 0, 0], array_map(static fn (array $row): int => $row[0], $sent));
        self::assertStringContainsString('expires on ' . ExpiryDate::format($this->today()->modify('+30 days')), $sent[0][1]);

        $connection = $this->connection($kernel);
        self::assertSame(25, (int) $connection->fetchOne('SELECT COUNT(*) FROM instance_setting_secret_expiry_notice'), '5 reminders x (3 bells + 2 mails)');
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM instance_setting_secret_expiry_notice WHERE channel = 'mail' AND recipient = '3'"));
        $bells = $connection->fetchAllAssociative('SELECT * FROM nowo_notify_notification ORDER BY id');
        // NotifyKit coalesces the repeats of one type and target within its repeat window (10 minutes): one row per type
        // and person here, days apart in real life.
        self::assertCount(6, $bells, '2 types x 3 people');
        self::assertSame(['1', '2', '3'], array_values(array_unique(array_map(static fn (array $row): string => (string) $row['recipient_id'], $bells))));
        self::assertSame(['instance_settings.secret_expiring', 'instance_settings.secret_expired'], array_values(array_unique(array_map(static fn (array $row): string => (string) $row['type'], $bells))));
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($bells));
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($connection->fetchAllAssociative('SELECT * FROM instance_setting_secret_expiry_notice')));
    }

    public function testTwoServersOnOneDatabaseSendEachReminderOnce(): void
    {
        $path   = sys_get_temp_dir() . '/nowo-instance-settings-kit88-' . uniqid() . '.sqlite';
        $config = ['expiring_secrets' => ['enabled' => true, 'timezone' => 'UTC', 'notify' => self::NOTIFY]];
        $first  = $this->boot($config, databaseUrl: 'sqlite:///' . $path);
        $second = $this->boot($config, databaseUrl: 'sqlite:///' . $path, createSchema: false);
        $event  = new SecretExpiryThresholdReachedEvent('providers', 'api_key', 'providers.api_key', 'app', ExpiryDate::format($this->today()->modify('+7 days')), 7, 7);

        try {
            // Both cron runs got past the threshold claim (e.g. a host listener dispatching the event on both).
            $this->notifier($first)($event);
            $this->notifier($second)($event);
            self::assertCount(2, $this->mails($first));
            self::assertSame([], $this->mails($second), 'the second server finds every marker taken');
            self::assertSame(2, (int) $this->connection($second)->fetchOne('SELECT COUNT(*) FROM instance_setting_secret_expiry_notice'), 'one mail marker per person with an address');

            $store = $this->container($second)->get(ExpiryNoticeStoreInterface::class);
            self::assertInstanceOf(DoctrineExpiryNoticeStore::class, $store);
            $key = new ExpiryNoticeKey('providers', 'api_key', $event->expiresOn, 7, 'mail', '1');
            self::assertFalse($store->claim($key, new DateTimeImmutable()));
            // Inside a transaction a refused claim leaves the transaction usable (own savepoint).
            $connection = $this->connection($second);
            $connection->beginTransaction();
            self::assertFalse($store->claim($key, new DateTimeImmutable()));
            self::assertTrue($store->claim(new ExpiryNoticeKey('providers', 'api_key', $event->expiresOn, 7, 'mail', '9'), new DateTimeImmutable()));
            $connection->commit();
            $store->release($key);
            self::assertTrue($store->claim($key, new DateTimeImmutable()), 'a released claim can be taken again');
        } finally {
            (new Filesystem())->remove($path);
        }
    }

    public function testTheDddHostKeepsTheMarkersOnTheSettingsManagerWithItsPrefix(): void
    {
        $kernel = $this->boot(['doctrine' => ['entity_manager' => 'settings', 'table_prefix' => 'app_'], 'expiring_secrets' => ['enabled' => true, 'timezone' => 'UTC', 'notify' => self::NOTIFY]], extensions: ['doctrine' => [
            'dbal' => ['default_connection' => 'default', 'connections' => ['settings' => ['url' => 'sqlite:///:memory:']]],
            'orm'  => ['default_entity_manager' => 'default', 'entity_managers' => ['default' => ['connection' => 'default', 'auto_mapping' => true], 'settings' => ['connection' => 'settings']]],
        ]]);
        $registry = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $host     = $registry->getManager('default');
        $settings = $registry->getManager('settings');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertInstanceOf(EntityManagerInterface::class, $settings);
        self::assertTrue($host->getMetadataFactory()->isTransient(SecretExpiryNotice::class), 'not on the domain manager');
        self::assertSame('app_instance_setting_secret_expiry_notice', $settings->getClassMetadata(SecretExpiryNotice::class)->getTableName());
        (new SchemaTool($settings))->createSchema($settings->getMetadataFactory()->getAllMetadata());

        $this->notifier($kernel)(new SecretExpiryThresholdReachedEvent('providers', 'api_key', 'providers.api_key', 'app', '2031-01-31', 30, 30));
        self::assertCount(2, $this->mails($kernel));
        $connection = $settings->getConnection();
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_instance_setting_secret_expiry_notice'));
        $row = $connection->fetchAssociative("SELECT * FROM app_instance_setting_secret_expiry_notice WHERE channel = 'mail' AND recipient = '1'");
        self::assertIsArray($row);
        self::assertSame(['providers', 'api_key', '2031-01-31', 30], [$row['section'], $row['name'], $row['expires_on'], (int) $row['threshold']]);
        self::assertSame((new ExpiryNoticeKey('providers', 'api_key', '2031-01-31', 30, 'mail', '1'))->hash(), $row['notice_key']);
        $entity = new SecretExpiryNotice('k', 's', 'n', new DateTimeImmutable('2031-01-31'), 30, 'mail', '1', new DateTimeImmutable('2026-11-18'));
        self::assertSame(['k', 's', 'n', '2031-01-31', 30, 'mail', '1', '2026-11-18'], [$entity->getNoticeKey(), $entity->getSection(), $entity->getName(), $entity->getExpiresOn()->format('Y-m-d'), $entity->getThreshold(), $entity->getChannel(), $entity->getRecipient(), $entity->getSentAt()->format('Y-m-d')]);
    }

    public function testOffByDefault(): void
    {
        $kernel   = $this->boot(['expiring_secrets' => ['enabled' => true, 'timezone' => 'UTC']]);
        $doctrine = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $manager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertTrue($manager->getMetadataFactory()->isTransient(SecretExpiryNotice::class), 'no sent-marker table without the flag (no migration)');
        self::assertFalse($this->container($kernel)->has(SecretExpiryNotifier::class));
        self::assertFalse($this->container($kernel)->has(ExpiryNoticeStoreInterface::class));

        $this->settings($kernel)->save('providers', ['api_key' => self::SECRET, 'api_key:expires_on' => $this->today()->modify('+7 days')]);
        (new CommandTester((new Application($kernel))->find(CheckExpiringSecretsCommand::NAME)))->execute([]);
        self::assertSame([], $this->mails($kernel), 'the 1.17 event only, for the host listeners');
    }

    private ?string $lastDeveloperMail = null;

    /**
     * @param array<string, mixed> $config
     * @param array<string, array<string, mixed>> $extensions
     */
    private function boot(array $config, bool $notifyKit = false, ?string $databaseUrl = null, bool $createSchema = true, array $extensions = []): InstanceSettingsTestKernel
    {
        $extensions['framework'] = ['router' => ['default_uri' => 'https://settings.example.test']];
        if ($notifyKit) {
            $extensions['nowo_notify_kit'] = ['enabled' => true];
        }

        return $this->bootKernel($config, ['providers_section' => ExpiringFixtureSection::class, ExpiryRecipientsFixture::class => ExpiryRecipientsFixture::class], extensions: $extensions, extraBundles: $notifyKit ? [new NowoNotifyKitBundle()] : [], databaseUrl: $databaseUrl, createSchema: $createSchema);
    }

    private function notifier(InstanceSettingsTestKernel $kernel): SecretExpiryNotifier
    {
        $notifier = $this->container($kernel)->get(SecretExpiryNotifier::class);
        self::assertInstanceOf(SecretExpiryNotifier::class, $notifier);

        return $notifier;
    }

    /**
     * The e-mails sent since the last call (the logger is emptied).
     *
     * @return list<Email>
     */
    private function mails(InstanceSettingsTestKernel $kernel): array
    {
        $logger = $this->container($kernel)->get('mailer.message_logger_listener');
        self::assertInstanceOf(MessageLoggerListener::class, $logger);
        $mails = [];
        foreach ($logger->getEvents()->getMessages() as $message) {
            self::assertInstanceOf(Email::class, $message);
            $mails[] = $message;
        }
        $logger->reset();
        if (count($mails) > 0) {
            $this->lastDeveloperMail = (string) $mails[0]->getHtmlBody();
        }

        return $mails;
    }

    private function resetter(InstanceSettingsTestKernel $kernel): ResetInterface
    {
        $resetter = $this->container($kernel)->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);

        return $resetter;
    }

    private function today(): DateTimeImmutable
    {
        return ExpiryDate::of(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }
}
