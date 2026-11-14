<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Expiry;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Nowo\InstanceSettingsBundle\Expiry\ExpiringSecret;
use Nowo\InstanceSettingsBundle\Expiry\ExpiringSecrets;
use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryChecker;
use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryRecord;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ExpiringFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySecretExpiryStore;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingDispatcher;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

use function array_map;
use function json_encode;
use function serialize;
use function var_export;

/**
 * 1.17: thresholds announced once per threshold per expiry date (across "restarts": a new checker over the same store),
 * a new date announced again, expired secrets, listener failures retried, the read port and the page warnings.
 */
final class SecretExpiryCheckerTest extends TestCase
{
    private const SECRET = 'sk-live-THE-SECRET-VALUE';

    private InMemorySettingValueRepository $repository;

    private InMemorySecretExpiryStore $store;

    private MockClock $clock;

    private InstanceSettings $settings;

    protected function setUp(): void
    {
        $this->repository = new InMemorySettingValueRepository();
        $this->store      = new InMemorySecretExpiryStore();
        $this->clock      = new MockClock(new DateTimeImmutable('2026-11-14 09:00:00', new DateTimeZone('UTC')));
        $this->settings   = new InstanceSettings($this->repository, $this->registry(), new ValueNormalizer(), clock: $this->clock, expiryStore: $this->store);
        $this->settings->save('providers', ['api_key' => self::SECRET, 'api_key:expires_on' => '2026-12-31']);
    }

    public function testEachThresholdIsAnnouncedOncePerDateAcrossRestarts(): void
    {
        $dispatcher = new RecordingDispatcher();
        $announced  = [];
        foreach (['2026-11-14' => [], '2026-12-01' => [30], '2026-12-02' => [], '2026-12-24' => [7], '2026-12-30' => [1], '2026-12-31' => [0], '2027-01-05' => []] as $day => $expected) {
            $this->clock->modify($day . ' 09:00:00');
            // A new checker per day: the "once" state lives in the store only (restart / another worker).
            $report = $this->checker($dispatcher)->check();
            self::assertSame($expected, array_map(static fn (SecretExpiryThresholdReachedEvent $event): int => $event->threshold, $report->announced), $day);
            $announced = [...$announced, ...$report->announced];
            // A second run on the same day announces nothing.
            self::assertSame([], $this->checker($dispatcher)->check()->announced, $day . ' (again)');
        }
        self::assertSame($announced, $dispatcher->events);
        $last = $announced[3];
        self::assertSame(['providers', 'api_key', 'providers.api_key', 'app', '2026-12-31', 0, 0, false], [$last->section, $last->field, $last->label, $last->translationDomain, $last->expiresOn, $last->threshold, $last->daysLeft, $last->isExpired()]);
        self::assertSame('providers.api_key', $last->key());
    }

    public function testSeveralCrossedThresholdsAnnounceOnlyTheSmallest(): void
    {
        $this->clock->modify('2026-12-27 09:00:00');
        $report = $this->checker()->check();

        self::assertCount(1, $report->announced);
        self::assertSame([7, 4], [$report->announced[0]->threshold, $report->announced[0]->daysLeft]);
        self::assertSame(7, $this->store->records['providers.api_key']->notifiedThreshold, 'the larger thresholds count as announced');
    }

    public function testANewDateIsAnnouncedAgain(): void
    {
        $this->clock->modify('2026-12-25 09:00:00');
        self::assertCount(1, $this->checker()->check()->announced);

        // Renewed for a year with the same key: a new date, its thresholds start over.
        $this->settings->save('providers', ['api_key:expires_on' => '2027-01-01']);
        $report = $this->checker()->check();
        self::assertSame([7], array_map(static fn (SecretExpiryThresholdReachedEvent $event): int => $event->threshold, $report->announced));
        self::assertSame('2027-01-01', $report->announced[0]->expiresOn);
    }

    public function testAnExpiredSecretIsAnnouncedOnceAtThresholdZero(): void
    {
        $this->clock->modify('2027-02-10 09:00:00');
        $report = $this->checker()->check();

        self::assertCount(1, $report->announced);
        self::assertSame([0, -41, true], [$report->announced[0]->threshold, $report->announced[0]->daysLeft, $report->announced[0]->isExpired()]);
        self::assertSame([], $this->checker()->check()->announced);
    }

    public function testTheDayBoundaryFollowsTheConfiguredZone(): void
    {
        // 23:30 UTC on 30 December: still the 30th in UTC (1 day left), already the 31st in Madrid (the day itself).
        $this->clock->modify('2026-12-30 23:30:00');
        self::assertSame(1, $this->checker(timezone: 'UTC')->check(true)->announced[0]->threshold);
        self::assertSame(0, $this->checker(timezone: 'Europe/Madrid')->check(true)->announced[0]->threshold);
    }

    public function testADryRunRecordsAndDispatchesNothing(): void
    {
        $dispatcher = new RecordingDispatcher();
        $this->clock->modify('2026-12-01 09:00:00');

        $report = $this->checker($dispatcher)->check(true);

        self::assertTrue($report->dryRun);
        self::assertCount(1, $report->announced);
        self::assertSame([], $dispatcher->events);
        self::assertNull($this->store->records['providers.api_key']->notifiedThreshold);
    }

    public function testAFailingListenerReleasesTheClaimAndTheNextRunRetries(): void
    {
        $this->clock->modify('2026-12-01 09:00:00');
        $logger  = new RecordingLogger();
        $failing = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                throw new RuntimeException('mailer down sk-live-THE-SECRET-VALUE');
            }
        };

        $report = $this->checker($failing, $logger)->check();
        self::assertSame([], $report->announced);
        self::assertSame(['providers.api_key'], $report->failed);
        self::assertNull($this->store->records['providers.api_key']->notifiedThreshold, 'released');
        self::assertStringNotContainsString(self::SECRET, $logger->dump(), 'the exception message is never logged');
        self::assertStringContainsString(RuntimeException::class, $logger->dump());

        $dispatcher = new RecordingDispatcher();
        self::assertCount(1, $this->checker($dispatcher)->check()->announced);
        self::assertCount(1, $dispatcher->events);
    }

    public function testALostClaimIsNotAnnounced(): void
    {
        $dispatcher              = new RecordingDispatcher();
        $this->store->loseClaims = 1;
        $this->clock->modify('2026-12-01 09:00:00');

        self::assertSame([], $this->checker($dispatcher)->check()->announced, 'another run won the claim');
        self::assertSame([], $dispatcher->events);
    }

    public function testDatesThatDoNotCountAreIgnored(): void
    {
        $this->clock->modify('2026-12-31 09:00:00');
        $this->store->records['gone.api_key']            = new SecretExpiryRecord('gone', 'api_key', new DateTimeImmutable('2026-12-31'));
        $this->store->records['providers.webhook_token'] = new SecretExpiryRecord('providers', 'webhook_token', new DateTimeImmutable('2026-12-31'));
        $this->store->records['providers.removed']       = new SecretExpiryRecord('providers', 'removed', new DateTimeImmutable('2026-12-31'));
        $this->store->records['providers.backup_key']    = new SecretExpiryRecord('providers', 'backup_key', new DateTimeImmutable('2026-12-31'));

        $report = $this->checker()->check();

        self::assertSame(['providers.api_key'], array_map(static fn (SecretExpiryThresholdReachedEvent $event): string => $event->key(), $report->announced), 'unknown section, no expires, unknown field, no secret');
    }

    public function testNoThresholdReachedYet(): void
    {
        self::assertSame([], $this->checker()->check()->announced);
    }

    public function testNoOutputCarriesTheSecret(): void
    {
        $this->clock->modify('2026-12-31 09:00:00');
        $logger = new RecordingLogger();
        $report = $this->checker(new RecordingDispatcher(), $logger)->check();
        $source = $this->secrets();

        foreach ([serialize($report), var_export($report, true), json_encode($report), serialize($source->expiringWithin(30, $this->clock->now())), $logger->dump()] as $output) {
            self::assertIsString($output);
            self::assertStringNotContainsString(self::SECRET, $output);
        }
        self::assertStringContainsString('Instance settings secret expiry announced.', $logger->dump());
    }

    public function testTheReadPortAndThePageWarnings(): void
    {
        $this->settings->save('providers', ['mode' => 'backup', 'backup_key' => 'bk-1', 'backup_key:expires_on' => '2026-11-10']);
        $source = $this->secrets();

        $due = $source->expiringWithin(60, new DateTimeImmutable('2026-11-14 23:30', new DateTimeZone('Europe/Madrid')));
        self::assertSame(['providers.backup_key', 'providers.api_key'], array_map(static fn (ExpiringSecret $secret): string => $secret->key(), $due), 'soonest first, expired included');
        self::assertSame([-4, true, '2026-11-10'], [$due[0]->daysLeft, $due[0]->isExpired(), $due[0]->expiresOnString()]);
        self::assertSame([47, false, 'providers.api_key', 'app'], [$due[1]->daysLeft, $due[1]->isExpired(), $due[1]->label, $due[1]->translationDomain]);
        self::assertSame(['providers.backup_key'], array_map(static fn (ExpiringSecret $secret): string => $secret->key(), $source->expiringWithin(0, new DateTimeImmutable('2026-11-14'))));

        self::assertSame(['backup_key'], array_keys($source->warnings('providers')), 'warning_days 30: the API key (47 days) is not yet due');
        self::assertSame([], $source->warnings('general'));
        self::assertSame([], $this->secrets(null)->warnings('providers'), 'warning off');
        self::assertSame(['api_key', 'backup_key'], array_keys($source->datesOf('providers')));
        self::assertSame([], $source->datesOf('general'));
        self::assertSame('2026-11-14', $source->today()->format('Y-m-d'));
    }

    private function registry(): SettingsSectionRegistry
    {
        return new SettingsSectionRegistry([new ExpiringFixtureSection(), new GeneralFixtureSection()]);
    }

    private function secrets(?int $warningDays = 30, ?string $timezone = 'UTC'): ExpiringSecrets
    {
        return new ExpiringSecrets($this->registry(), $this->store, $this->settings, $this->clock, $timezone, $warningDays);
    }

    private function checker(?EventDispatcherInterface $dispatcher = null, ?RecordingLogger $logger = null, string $timezone = 'UTC'): SecretExpiryChecker
    {
        $this->settings->reset();

        return new SecretExpiryChecker($this->secrets(timezone: $timezone), $this->store, [0, 7, 30, 1], $dispatcher ?? new RecordingDispatcher(), $logger, $this->clock);
    }
}
