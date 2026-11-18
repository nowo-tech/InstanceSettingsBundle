<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Expiry\Notify;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Nowo\InstanceSettingsBundle\Expiry\ExpiringSecrets;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeFailedException;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeKey;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeStoreInterface;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryRecipient;
use Nowo\InstanceSettingsBundle\Expiry\Notify\PermanentExpiryNoticeFailureException;
use Nowo\InstanceSettingsBundle\Expiry\Notify\SecretExpiryNotifier;
use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryChecker;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ExpiringFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ExpiryRecipientsFixture;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemoryExpiryNoticeStore;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySecretExpiryStore;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingExpiryChannel;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_map;
use function array_slice;

/**
 * 1.21 (KIT-88): the default expiry notifier behind the 1.17 checker — every threshold (30, 7, 1, 0 and expired) once
 * per secret, threshold, channel and recipient across restarts and concurrent runs, `notify.days`, duplicate and
 * unreachable recipients, and a failed delivery retried for that recipient only.
 */
final class SecretExpiryNotifierTest extends TestCase
{
    private const SECRET = 'sk-live-THE-SECRET-VALUE';

    private InMemorySecretExpiryStore $expiry;

    private InMemoryExpiryNoticeStore $markers;

    private MockClock $clock;

    private InstanceSettings $settings;

    private ExpiryRecipientsFixture $recipients;

    protected function setUp(): void
    {
        $this->expiry     = new InMemorySecretExpiryStore();
        $this->markers    = new InMemoryExpiryNoticeStore();
        $this->clock      = new MockClock(new DateTimeImmutable('2026-11-14 09:00:00', new DateTimeZone('UTC')));
        $this->settings   = new InstanceSettings(new InMemorySettingValueRepository(), new SettingsSectionRegistry([new ExpiringFixtureSection(), new GeneralFixtureSection()]), new ValueNormalizer(), clock: $this->clock, expiryStore: $this->expiry);
        $this->recipients = new ExpiryRecipientsFixture();
        $this->settings->save('providers', ['api_key' => self::SECRET, 'api_key:expires_on' => '2026-12-31']);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function thresholdDays(): iterable
    {
        yield '30 days before' => ['2026-12-01', ['1:providers.api_key:30', '2:providers.api_key:30', '3:providers.api_key:30']];
        yield '7 days before' => ['2026-12-24', ['1:providers.api_key:7', '2:providers.api_key:7', '3:providers.api_key:7']];
        yield '1 day before' => ['2026-12-30', ['1:providers.api_key:1', '2:providers.api_key:1', '3:providers.api_key:1']];
        yield 'on the day' => ['2026-12-31', ['1:providers.api_key:0', '2:providers.api_key:0', '3:providers.api_key:0']];
        yield 'expired' => ['2027-01-03', ['1:providers.api_key:0', '2:providers.api_key:0', '3:providers.api_key:0']];
        yield 'before the first threshold' => ['2026-11-30', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('thresholdDays')]
    public function testEachThresholdNotifiesEveryRecipientOnce(string $day, array $expected): void
    {
        $this->clock->modify($day . ' 09:00:00');
        $bell = new RecordingExpiryChannel('bell');
        $mail = new RecordingExpiryChannel('mail', needsEmail: true);

        $this->runDay([$mail, $bell]);
        self::assertSame($expected, $bell->sent);
        // Recipient 3 has no address: no mail; recipient 1 listed twice: once.
        self::assertSame(array_values(array_filter($expected, static fn (string $line): bool => !str_starts_with($line, '3:'))), $mail->sent);
        self::assertCount($expected === [] ? 0 : 5, $this->markers->markers, '3 bells + 2 mails');

        // Same day again, a restarted worker (new notifier and checker over the same stores): nothing more.
        $this->runDay([$mail, $bell]);
        self::assertSame($expected, $bell->sent);
        if ($expected !== []) {
            self::assertSame([['ROLE_DEVELOPER', 'ROLE_MANAGER']], $this->recipients->calls);
        }
    }

    public function testTheWholeCalendarSendsFourReminders(): void
    {
        $bell = new RecordingExpiryChannel('bell');
        foreach (['2026-11-14', '2026-12-01', '2026-12-02', '2026-12-24', '2026-12-30', '2026-12-31', '2027-01-05'] as $day) {
            $this->clock->modify($day . ' 09:00:00');
            $this->runDay([$bell]);
            $this->runDay([$bell]);
        }
        self::assertSame([30, 7, 1, 0], array_values(array_unique(array_map(static fn (string $line): int => (int) substr($line, strrpos($line, ':') + 1), $bell->sent))));
        self::assertCount(12, $bell->sent, '4 thresholds x 3 recipients');

        // A renewed secret (new date) is reminded again.
        $this->settings->save('providers', ['api_key:expires_on' => '2027-01-12']);
        $this->runDay([$bell]);
        self::assertSame(['1:providers.api_key:7', '2:providers.api_key:7', '3:providers.api_key:7'], array_slice($bell->sent, 12));
    }

    public function testOnlyTheConfiguredDaysNotify(): void
    {
        $bell = new RecordingExpiryChannel('bell');
        $this->clock->modify('2026-12-01 09:00:00');
        $this->runDay([$bell], days: [7, 0]);
        self::assertSame([], $bell->sent, '30 is announced by the checker but not notified');
        self::assertSame([], $this->recipients->calls, 'nobody is even looked up');

        $this->clock->modify('2026-12-24 09:00:00');
        $this->runDay([$bell], days: [7, 0]);
        self::assertCount(3, $bell->sent);
    }

    public function testConcurrentRunsSendEachReminderOnce(): void
    {
        // Two cron runs that both got past the checker (the threshold claim of one released after a failure, a host
        // listener dispatching the event twice, ...): the sent-markers still allow one delivery per recipient.
        $event = new SecretExpiryThresholdReachedEvent('providers', 'api_key', 'providers.api_key', 'app', '2026-12-31', 7, 7);
        $first = new RecordingExpiryChannel('bell');
        $other = new RecordingExpiryChannel('bell');
        $this->notifier([$first])($event);
        $this->notifier([$other])($event);
        self::assertCount(3, $first->sent);
        self::assertSame([], $other->sent);

        // A store that lost the race (its unique key refused the claim) sends nothing.
        $lost = new class implements ExpiryNoticeStoreInterface {
            public function claim(ExpiryNoticeKey $key, DateTimeImmutable $at): bool
            {
                return false;
            }

            public function release(ExpiryNoticeKey $key): void
            {
            }

            public function purge(DateTimeImmutable $expiresBefore): int
            {
                return 0;
            }
        };
        $third = new RecordingExpiryChannel('bell');
        (new SecretExpiryNotifier($this->recipients, $lost, [$third], [7], ['ROLE_ADMIN']))($event);
        self::assertSame([], $third->sent);
    }

    public function testAFailedDeliveryIsRetriedForThatRecipientOnly(): void
    {
        $this->clock->modify('2026-12-24 09:00:00');
        $logger        = new RecordingLogger();
        $bell          = new RecordingExpiryChannel('bell');
        $mail          = new RecordingExpiryChannel('mail', needsEmail: true);
        $mail->failFor = ['2'];

        $report = $this->checker([$mail, $bell], $logger)->check();
        self::assertSame(['providers.api_key'], $report->failed, 'the checker keeps the threshold open');
        self::assertSame(['1:providers.api_key:7'], $mail->sent);
        self::assertCount(3, $bell->sent, 'the other deliveries went out');
        self::assertSame(1, $this->markers->releases);
        self::assertStringContainsString('"channel":"mail","recipient":"2"', $logger->dump());
        self::assertStringContainsString('ExpiryNoticeFailedException', $logger->dump());
        self::assertStringNotContainsString(self::SECRET, $logger->dump());

        // The next run (the mailer is back) sends only the missing mail.
        $mail->failFor = [];
        $report        = $this->checker([$mail, $bell], $logger)->check();
        self::assertSame([], $report->failed);
        self::assertSame(['1:providers.api_key:7', '2:providers.api_key:7'], $mail->sent);
        self::assertCount(3, $bell->sent);
    }

    public function testTheExceptionNamesTheSettingAndCountsOnly(): void
    {
        $bell          = new RecordingExpiryChannel('bell');
        $bell->failFor = ['1', '3'];
        $this->expectException(ExpiryNoticeFailedException::class);
        $this->expectExceptionMessage('2 expiry reminder(s) of providers.api_key (threshold 7) could not be delivered; they are retried on the next run.');
        $this->notifier([$bell])(new SecretExpiryThresholdReachedEvent('providers', 'api_key', 'providers.api_key', 'app', '2026-12-31', 7, 7));
    }

    public function testReleaseFailureLosesTheReminderForeverButNotTheOthers(): void
    {
        // Audit TB L1: the delivery fails AND the release fails (the database went away in between). That reminder stays
        // claimed (logged as lost, critical); the other recipients are still sent and the run reports the failure.
        $store = new class implements ExpiryNoticeStoreInterface {
            /** @var array<string, true> */
            public array $claimed = [];

            public function claim(ExpiryNoticeKey $key, DateTimeImmutable $at): bool
            {
                $this->claimed[$key->hash()] = true;

                return true;
            }

            public function release(ExpiryNoticeKey $key): void
            {
                throw new RuntimeException('server has gone away');
            }

            public function purge(DateTimeImmutable $expiresBefore): int
            {
                return 0;
            }
        };
        $logger        = new RecordingLogger();
        $bell          = new RecordingExpiryChannel('bell');
        $bell->failFor = ['1'];
        try {
            (new SecretExpiryNotifier($this->recipients, $store, [$bell], [7], ['ROLE_ADMIN'], $logger))(new SecretExpiryThresholdReachedEvent('providers', 'api_key', 'providers.api_key', 'app', '2026-12-31', 7, 7));
            self::fail('Expected ExpiryNoticeFailedException');
        } catch (ExpiryNoticeFailedException $e) {
            self::assertStringStartsWith('1 expiry reminder(s)', $e->getMessage());
        }
        self::assertSame(['2:providers.api_key:7', '3:providers.api_key:7'], $bell->sent, 'the loop went on');
        self::assertCount(3, $store->claimed);
        self::assertSame(['error', 'critical'], array_map(static fn (array $record): string => $record['level'], $logger->records));
        self::assertSame(RuntimeException::class, $logger->records[1]['context']['exception']);
        self::assertStringNotContainsString('server has gone away', $logger->dump(), 'exception class only');
    }

    public function testAPermanentFailureKeepsTheMarkerAndIsNotRetried(): void
    {
        $logger             = new RecordingLogger();
        $mail               = new RecordingExpiryChannel('mail', needsEmail: true);
        $mail->permanentFor = ['2'];
        $event              = new SecretExpiryThresholdReachedEvent('providers', 'api_key', 'providers.api_key', 'app', '2026-12-31', 7, 7);
        $this->notifier([$mail], $logger)($event);
        self::assertSame(['1:providers.api_key:7'], $mail->sent);
        self::assertCount(2, $this->markers->markers, 'the refused one stays marked');
        self::assertSame(0, $this->markers->releases);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(PermanentExpiryNoticeFailureException::class, $logger->records[0]['context']['exception']);

        $mail->permanentFor = [];
        $this->notifier([$mail], $logger)($event);
        self::assertSame(['1:providers.api_key:7'], $mail->sent, 'never retried');
    }

    public function testTwoChannelsWithOneNameAreRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('are both named "bell"');
        $this->notifier([new RecordingExpiryChannel('bell'), new RecordingExpiryChannel('bell', needsEmail: true)])(new SecretExpiryThresholdReachedEvent('providers', 'api_key', 'providers.api_key', 'app', '2026-12-31', 7, 7));
    }

    public function testAChannelNameThatDoesNotFitTheMarkerIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be named with [a-z0-9_]');
        $this->notifier([new RecordingExpiryChannel('Mail Channel')])(new SecretExpiryThresholdReachedEvent('providers', 'api_key', 'providers.api_key', 'app', '2026-12-31', 7, 7));
    }

    public function testTheRecipientValueObjectRefusesUnsafeValues(): void
    {
        $recipient = new ExpiryRecipient('user-1', 'a@example.test', 'pt_BR', true);
        self::assertSame(['user-1', 'a@example.test', 'pt_BR', true], [$recipient->id, $recipient->email, $recipient->locale, $recipient->canOpenSettings]);
        self::assertNull((new ExpiryRecipient('9'))->email);
        self::assertFalse((new ExpiryRecipient('9'))->canOpenSettings, 'fail closed: no settings link unless the host says so');
        self::assertTrue(ExpiryRecipient::isValidEmail('first.last+tag@sub.example.test'));
        foreach (['no-at', 'a@b@c.test', ' a@b.test', 'a@b.test ', "a@b.test\r\nBcc: x@evil.test", 'a b@c.test', '<a@b.test>', ''] as $bad) {
            self::assertFalse(ExpiryRecipient::isValidEmail($bad), (string) json_encode($bad));
        }

        foreach ([['', null, null], [' 1', null, null], ["1\n", null, null], [str_repeat('x', 129), null, null], ["\xff", null, null], ['1', 'no-at', null], ['1', "a@b.test\r\nBcc: x@evil.test", null], ['1', '', null], ['1', null, '../en'], ['1', null, 'EN']] as [$id, $email, $locale]) {
            try {
                new ExpiryRecipient($id, $email, $locale);
                self::fail('Expected a refusal for ' . json_encode([$id, $email, $locale]));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(str_repeat('é', 128), (new ExpiryRecipient(str_repeat('é', 128)))->id);
    }

    public function testTheKeyIsUnambiguous(): void
    {
        $event = new SecretExpiryThresholdReachedEvent('a', 'b.c', 'l', 'd', '2027-01-01', 7, 7);
        $key   = ExpiryNoticeKey::of($event, 'mail', new ExpiryRecipient('1'));
        self::assertSame(['a', 'b.c', '2027-01-01', 7, 'mail', '1'], [$key->section, $key->field, $key->expiresOn, $key->threshold, $key->channel, $key->recipient]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key->hash());
        self::assertNotSame($key->hash(), (new ExpiryNoticeKey('a.b', 'c', '2027-01-01', 7, 'mail', '1'))->hash(), 'section.field splits differently');
        self::assertNotSame($key->hash(), (new ExpiryNoticeKey('a', 'b.c', '2027-01-02', 7, 'mail', '1'))->hash(), 'another date');
        self::assertNotSame($key->hash(), (new ExpiryNoticeKey('a', 'b.c', '2027-01-01', 1, 'mail', '1'))->hash(), 'another threshold');
        self::assertNotSame($key->hash(), (new ExpiryNoticeKey('a', 'b.c', '2027-01-01', 7, 'bell', '1'))->hash(), 'another channel');
        self::assertNotSame($key->hash(), (new ExpiryNoticeKey('a', 'b.c', '2027-01-01', 7, 'mail', '2'))->hash(), 'another recipient');
    }

    /**
     * @param list<RecordingExpiryChannel> $channels
     * @param list<int> $days
     */
    private function runDay(array $channels, array $days = [30, 7, 1, 0]): void
    {
        $this->checker($channels, days: $days)->check();
    }

    /**
     * @param list<RecordingExpiryChannel> $channels
     * @param list<int> $days
     */
    private function checker(array $channels, ?RecordingLogger $logger = null, array $days = [30, 7, 1, 0]): SecretExpiryChecker
    {
        $this->settings->reset();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SecretExpiryThresholdReachedEvent::class, $this->notifier($channels, $logger, $days));

        return new SecretExpiryChecker(new ExpiringSecrets(new SettingsSectionRegistry([new ExpiringFixtureSection(), new GeneralFixtureSection()]), $this->expiry, $this->settings, $this->clock, 'UTC', 30), $this->expiry, [30, 7, 1, 0], $dispatcher, $logger, $this->clock);
    }

    /**
     * @param list<RecordingExpiryChannel> $channels
     * @param list<int> $days
     */
    private function notifier(array $channels, ?RecordingLogger $logger = null, array $days = [30, 7, 1, 0]): SecretExpiryNotifier
    {
        return new SecretExpiryNotifier($this->recipients, $this->markers, $channels, $days, ['ROLE_DEVELOPER', 'ROLE_MANAGER'], $logger, $this->clock);
    }
}
