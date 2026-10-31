<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Settings;

use DateTimeImmutable;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Validation;

use const JSON_THROW_ON_ERROR;

final class InstanceSettingsTest extends TestCase
{
    private InMemorySettingValueRepository $repository;

    private RecordingDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->repository = new InMemorySettingValueRepository();
        $this->dispatcher = new RecordingDispatcher();
    }

    public function testDefaultsAndTypedGetters(): void
    {
        $settings = $this->settings();

        self::assertSame('noreply@example.test', $settings->get('mailer.from'));
        self::assertSame('noreply@example.test', $settings->getString('mailer.from'));
        self::assertSame(20, $settings->getInt('general.page_size'));
        self::assertSame(1.5, $settings->getFloat('general.ratio'));
        self::assertFalse($settings->getBool('mailer.enabled'));
        self::assertNull($settings->getString('mailer.test_recipient'));
        self::assertNull($settings->getSecret('mailer.dsn'));
        self::assertFalse($settings->hasSecret('mailer.dsn'));
        self::assertFalse($settings->isOverridden('mailer.from'));
        self::assertSame(['from' => 'noreply@example.test', 'from_name' => 'Example', 'test_recipient' => null, 'enabled' => false], $settings->getSection('mailer'));
        self::assertSame(1, $this->repository->queries, 'one query for every read of the request');
    }

    public function testSaveStoresChangesOnceAndDispatches(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $settings = $this->settings($logger);

        $changed = $settings->save('mailer', ['from' => ' ops@example.test ', 'dsn' => 'smtp://REDACTED-TEST@localhost', 'enabled' => '1'], SettingsChangedEvent::ORIGIN_FORM);
        self::assertSame(['from', 'dsn', 'enabled'], $changed);
        self::assertSame('ops@example.test', $settings->get('mailer.from'));
        self::assertSame('smtp://REDACTED-TEST@localhost', $settings->getSecret('mailer.dsn'));
        self::assertTrue($settings->hasSecret('mailer.dsn'));
        self::assertTrue($settings->getBool('mailer.enabled'));
        self::assertTrue($settings->isOverridden('mailer.from'));

        $event = $this->dispatcher->events[0];
        self::assertInstanceOf(SettingsChangedEvent::class, $event);
        self::assertSame(['mailer', ['from', 'dsn', 'enabled'], ['dsn'], 'form'], [$event->section, $event->changedFields, $event->secretFields, $event->origin]);
        self::assertSame('Instance settings saved.', $logger->records[0][0]);
        self::assertStringNotContainsString('REDACTED', json_encode($logger->records, JSON_THROW_ON_ERROR));

        // Same values again: nothing written, nothing dispatched.
        self::assertSame([], $settings->save('mailer', ['from' => 'ops@example.test', 'dsn' => 'smtp://REDACTED-TEST@localhost', 'enabled' => true]));
        self::assertSame(1, $this->repository->flushes);
        self::assertCount(1, $this->dispatcher->events);

        // Null / blank removes the stored value (plain → default, secret → none).
        self::assertSame(['from', 'dsn'], $settings->save('mailer', ['from' => null, 'dsn' => '', 'test_recipient' => null]));
        self::assertSame('noreply@example.test', $settings->get('mailer.from'));
        self::assertNull($settings->getSecret('mailer.dsn'));
    }

    public function testSecretReplacedAndPlainRowTurnedSecret(): void
    {
        $settings = $this->settings();
        $settings->save('mailer', ['dsn' => 'smtp://a']);
        self::assertSame(['dsn'], $settings->save('mailer', ['dsn' => 'smtp://b']));
        self::assertSame('smtp://b', $settings->getSecret('mailer.dsn'));
    }

    public function testValidationIsAllOrNothing(): void
    {
        $settings = $this->settings();

        try {
            $settings->save('general', ['site_name' => 'Ok', 'page_size' => 500, 'homepage' => 'nope']);
            self::fail('Expected InvalidSettingValueException');
        } catch (InvalidSettingValueException $e) {
            self::assertSame(['page_size', 'homepage'], array_keys($e->violations));
        }

        self::assertSame(0, $this->repository->flushes);
        self::assertSame('Acme', $settings->get('general.site_name'));
    }

    public function testMisuseIsRefused(): void
    {
        $settings = $this->settings();

        foreach ([
            static fn (): bool|float|int|string|null => $settings->get('mailer.dsn'),
            static fn (): ?string => $settings->getSecret('mailer.from'),
            static fn (): ?string => $settings->getString('general.page_size'),
            static fn (): ?int => $settings->getInt('general.site_name'),
            static fn (): ?float => $settings->getFloat('general.site_name'),
            static fn (): bool => $settings->getBool('general.site_name'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected LogicException');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(UnknownSettingException::class);
        $settings->save('mailer', ['nope' => 1]);
    }

    public function testStaleStoredTypeFallsBackToDefault(): void
    {
        $this->repository->rows[1] = (new SettingValue('general', 'page_size', new DateTimeImmutable()))->storeValue('not-an-int', new DateTimeImmutable());
        $this->repository->rows[2] = (new SettingValue('general', 'ratio', new DateTimeImmutable()))->storeValue(null, new DateTimeImmutable());

        self::assertSame(20, $this->settings()->getInt('general.page_size'));
        self::assertSame(1.5, $this->settings()->getFloat('general.ratio'));
    }

    public function testMemoIsBoundToTheMainRequest(): void
    {
        $stack    = new RequestStack([new Request()]);
        $settings = new InstanceSettings($this->repository, $this->registry(), requestStack: $stack, clock: new MockClock());

        $settings->get('mailer.from');
        $settings->get('mailer.from_name');
        self::assertSame(1, $this->repository->queries);

        $stack->pop();
        $stack->push(new Request());
        $settings->get('mailer.from');
        self::assertSame(2, $this->repository->queries, 'a new main request reloads');

        $stack->pop();
        $settings->get('mailer.from');
        $settings->get('mailer.from');
        self::assertSame(3, $this->repository->queries, 'outside HTTP the memo is rebuilt once');

        $settings->reset();
        $settings->get('mailer.from');
        self::assertSame(4, $this->repository->queries);
    }

    private function registry(): SettingsSectionRegistry
    {
        return new SettingsSectionRegistry([new MailerFixtureSection(), new GeneralFixtureSection()]);
    }

    private function settings(?AbstractLogger $logger = null): InstanceSettings
    {
        return new InstanceSettings(
            $this->repository,
            $this->registry(),
            new ValueNormalizer(Validation::createValidator()),
            $this->dispatcher,
            null,
            null,
            $logger,
        );
    }
}
