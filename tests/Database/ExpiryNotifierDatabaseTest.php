<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeKey;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeStoreInterface;
use Nowo\InstanceSettingsBundle\Expiry\Notify\SecretExpiryNotifier;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ExpiryRecipientsFixture;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;

use function count;
use function str_repeat;

/**
 * 1.21 (KIT-88): the expiry notifier's sent-markers on MySQL / MariaDB / PostgreSQL — two "servers" on one database
 * send each reminder once, and a refused claim inside a transaction leaves it usable (its own savepoint).
 */
final class ExpiryNotifierDatabaseTest extends DatabaseTestCase
{
    private const CONFIG = ['expiring_secrets' => ['enabled' => true, 'timezone' => 'UTC', 'notify' => [
        'enabled'            => true,
        'recipient_provider' => ExpiryRecipientsFixture::class,
        'channels'           => ['mail' => 'on'],
        'mail_from'          => 'noreply@settings.example.test',
    ]]];

    public function testTwoServersSendEachReminderOnce(): void
    {
        $first  = $this->boot(self::CONFIG, [ExpiryRecipientsFixture::class => ExpiryRecipientsFixture::class]);
        $second = $this->boot(self::CONFIG, [ExpiryRecipientsFixture::class => ExpiryRecipientsFixture::class]);
        $event  = new SecretExpiryThresholdReachedEvent('providers', 'api_key', 'providers.api_key', 'app', '2031-01-31', 7, 7);

        $this->notifier($first)($event);
        $this->notifier($second)($event);
        self::assertSame(2, $this->mailCount($first));
        self::assertSame(0, $this->mailCount($second));
        self::assertSame(2, (int) $this->connection($second)->fetchOne('SELECT COUNT(*) FROM instance_setting_secret_expiry_notice'));
    }

    public function testARefusedClaimInsideATransactionKeepsItUsable(): void
    {
        $kernel = $this->boot(self::CONFIG, [ExpiryRecipientsFixture::class => ExpiryRecipientsFixture::class]);
        $store  = $this->container($kernel)->get(ExpiryNoticeStoreInterface::class);
        self::assertInstanceOf(ExpiryNoticeStoreInterface::class, $store);
        $key = new ExpiryNoticeKey('providers', 'api_key', '2031-01-31', 0, 'mail', '1');
        self::assertTrue($store->claim($key, new DateTimeImmutable()));

        $connection = $this->connection($kernel);
        $connection->beginTransaction();
        self::assertFalse($store->claim($key, new DateTimeImmutable()));
        self::assertTrue($store->claim(new ExpiryNoticeKey('providers', 'api_key', '2031-01-31', 0, 'mail', '2'), new DateTimeImmutable()), 'the transaction is still usable');
        $connection->commit();
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM instance_setting_secret_expiry_notice'));

        $store->release($key);
        self::assertTrue($store->claim($key, new DateTimeImmutable()));

        // Audit TB L2: an error other than the duplicate (a recipient id longer than the column: PostgreSQL / MySQL
        // refuse it) also goes back to the claim's savepoint, so the caller's transaction is not left aborted.
        $connection->beginTransaction();
        try {
            $store->claim(new ExpiryNoticeKey('providers', 'api_key', '2031-01-31', 0, 'mail', str_repeat('x', 300)), new DateTimeImmutable());
            self::fail('Expected the database to refuse the value');
        } catch (Exception $e) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM instance_setting_secret_expiry_notice'), 'the transaction still answers');
        self::assertTrue($store->claim(new ExpiryNoticeKey('providers', 'api_key', '2031-01-31', 0, 'mail', '3'), new DateTimeImmutable()));
        $connection->commit();
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM instance_setting_secret_expiry_notice'));
    }

    private function notifier(InstanceSettingsTestKernel $kernel): SecretExpiryNotifier
    {
        $notifier = $this->container($kernel)->get(SecretExpiryNotifier::class);
        self::assertInstanceOf(SecretExpiryNotifier::class, $notifier);

        return $notifier;
    }

    private function mailCount(InstanceSettingsTestKernel $kernel): int
    {
        $logger = $this->container($kernel)->get('mailer.message_logger_listener');
        self::assertInstanceOf(MessageLoggerListener::class, $logger);

        return count($logger->getEvents()->getMessages());
    }
}
