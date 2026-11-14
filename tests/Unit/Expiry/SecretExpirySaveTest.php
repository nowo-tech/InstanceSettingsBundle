<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Expiry;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;
use Nowo\InstanceSettingsBundle\Expiry\ExpiryDate;
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
use Symfony\Component\Clock\MockClock;

/**
 * 1.17: the "a new secret clears the date unless one is given with it" rule of InstanceSettings::save().
 */
final class SecretExpirySaveTest extends TestCase
{
    private InMemorySettingValueRepository $repository;

    private InMemorySecretExpiryStore $store;

    private RecordingDispatcher $dispatcher;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->repository = new InMemorySettingValueRepository();
        $this->store      = new InMemorySecretExpiryStore();
        $this->dispatcher = new RecordingDispatcher();
        $this->logger     = new RecordingLogger();
    }

    public function testANewSecretWithADateStoresBoth(): void
    {
        $changed = $this->settings()->save('providers', ['api_key' => 'sk-live-111', 'api_key:expires_on' => '2027-01-31']);

        self::assertSame(['api_key', 'api_key:expires_on'], $changed);
        self::assertSame('2027-01-31', $this->date('api_key'));
        self::assertCount(1, $this->dispatcher->events);
        $event = $this->dispatcher->events[0];
        self::assertInstanceOf(SettingsChangedEvent::class, $event);
        self::assertSame(['api_key'], $event->changedFields, 'the event lists field names only');
        self::assertSame(1, $this->repository->transactions, 'the secret and its date are written in one transaction');
        self::assertStringNotContainsString('sk-live-111', $this->logger->dump());
        self::assertStringContainsString('api_key:expires_on', $this->logger->dump());
    }

    public function testANewSecretWithoutADateClearsTheStoredDate(): void
    {
        $settings = $this->settings();
        $settings->save('providers', ['api_key' => 'sk-live-111', 'api_key:expires_on' => '2027-01-31']);

        self::assertSame(['api_key', 'api_key:expires_on'], $settings->save('providers', ['api_key' => 'sk-live-222']));
        self::assertNull($this->date('api_key'), 'a rotated secret forgets the old date');
        self::assertSame('sk-live-222', $settings->getSecret('providers.api_key'));
    }

    public function testANewSecretWithANewDateReplacesIt(): void
    {
        $settings = $this->settings();
        $settings->save('providers', ['api_key' => 'sk-live-111', 'api_key:expires_on' => '2027-01-31']);

        $settings->save('providers', ['api_key' => 'sk-live-222', 'api_key:expires_on' => new DateTimeImmutable('2028-01-31 23:30', new DateTimeZone('America/New_York'))]);

        self::assertSame('2028-01-31', $this->date('api_key'), 'a date-time counts by its own calendar date');
    }

    public function testARemovedSecretRemovesItsDate(): void
    {
        $settings = $this->settings();
        $settings->save('providers', ['api_key' => 'sk-live-111', 'api_key:expires_on' => '2027-01-31']);

        self::assertSame(['api_key', 'api_key:expires_on'], $settings->save('providers', ['api_key' => null, 'api_key:expires_on' => '2029-01-01']));
        self::assertNull($this->date('api_key'));
        self::assertFalse($settings->hasSecret('providers.api_key'));
    }

    public function testTheSameSecretKeepsTheDate(): void
    {
        $settings = $this->settings();
        $settings->save('providers', ['api_key' => 'sk-live-111', 'api_key:expires_on' => '2027-01-31']);
        $settings->reset();

        self::assertSame([], $settings->save('providers', ['api_key' => 'sk-live-111']), 'an unchanged secret is not a new secret');
        self::assertSame('2027-01-31', $this->date('api_key'));
    }

    public function testADateAloneChangesOnlyTheDate(): void
    {
        $settings = $this->settings();
        $settings->save('providers', ['api_key' => 'sk-live-111']);
        $this->dispatcher->events = [];

        self::assertSame(['api_key:expires_on'], $settings->save('providers', ['api_key:expires_on' => '2027-03-01']));
        self::assertSame('2027-03-01', $this->date('api_key'));
        self::assertSame([], $this->dispatcher->events, 'a date-only change is not a settings change');
        self::assertSame('sk-live-111', $settings->getSecret('providers.api_key'));

        self::assertSame([], $settings->save('providers', ['api_key:expires_on' => '2027-03-01']), 'the same date changes nothing');
        self::assertSame(['api_key:expires_on'], $settings->save('providers', ['api_key:expires_on' => '']));
        self::assertNull($this->date('api_key'));
        self::assertSame([], $settings->save('providers', ['api_key:expires_on' => null]), 'removing a missing date changes nothing');
    }

    public function testADateWithoutASecretIsRefused(): void
    {
        try {
            $this->settings()->save('providers', ['api_key:expires_on' => '2027-03-01']);
            self::fail('Expected InvalidSettingValueException');
        } catch (InvalidSettingValueException $e) {
            self::assertSame(['api_key:expires_on'], array_keys($e->violations));
        }
        self::assertSame([], $this->store->records);
    }

    public function testMalformedDatesAreViolationsAndNothingIsWritten(): void
    {
        foreach (['2027-02-30', '31/01/2027', '2027-1-1', 20270131, ['2027-01-31'], '10000-01-01'] as $value) {
            try {
                $this->settings()->save('providers', ['api_key' => 'sk-live-111', 'api_key:expires_on' => $value]);
                self::fail('Expected InvalidSettingValueException');
            } catch (InvalidSettingValueException $e) {
                self::assertSame(['api_key:expires_on' => ['This value is not a valid date.']], $e->violations);
            }
        }
        self::assertSame([], $this->repository->rows, 'all-or-nothing: the secret is not saved either');
    }

    public function testTheCompanionKeyOfAFieldWithoutExpiresIsUnknown(): void
    {
        $this->expectException(UnknownSettingException::class);
        $this->settings()->save('providers', ['webhook_token:expires_on' => '2027-01-31']);
    }

    public function testASectionWithoutExpiringSecretsNeverTouchesTheStore(): void
    {
        $settings = new InstanceSettings($this->repository, new SettingsSectionRegistry([new GeneralFixtureSection()]), new ValueNormalizer(), $this->dispatcher);

        self::assertSame(['site_name'], $settings->save('general', ['site_name' => 'Beacon']));
        self::assertSame(0, $this->repository->transactions, 'byte-identical path: one apply, no transaction');
        self::assertSame(0, $this->store->writes);
    }

    public function testASecretWithoutDateNeverWritesTheStore(): void
    {
        $this->settings()->save('providers', ['webhook_token' => 'whk-1']);

        self::assertSame(0, $this->store->writes);
        self::assertSame(0, $this->repository->transactions);
    }

    public function testAnExpiringSectionNeedsTheStore(): void
    {
        $settings = new InstanceSettings($this->repository, new SettingsSectionRegistry([new ExpiringFixtureSection()]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('expiring_secrets.enabled');
        $settings->save('providers', ['api_key' => 'sk-live-111']);
    }

    private function settings(): InstanceSettings
    {
        return new InstanceSettings(
            $this->repository,
            new SettingsSectionRegistry([new ExpiringFixtureSection()]),
            new ValueNormalizer(),
            $this->dispatcher,
            null,
            new MockClock('2026-11-14 10:00:00'),
            $this->logger,
            expiryStore: $this->store,
        );
    }

    private function date(string $field): ?string
    {
        $record = $this->store->findBySection('providers')[$field] ?? null;

        return $record === null ? null : ExpiryDate::format($record->expiresOn);
    }
}
