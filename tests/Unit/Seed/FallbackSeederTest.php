<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Seed;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Seed\FallbackSeeder;
use Nowo\InstanceSettingsBundle\Seed\SeedReport;
use Nowo\InstanceSettingsBundle\Seed\TransactionStateGuard;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SeedFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\RacingRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingDispatcher;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

use function array_map;
use function bin2hex;
use function print_r;
use function random_bytes;
use function var_export;

final class FallbackSeederTest extends TestCase
{
    private InMemorySettingValueRepository $repository;

    private RecordingDispatcher $dispatcher;

    private InstanceSettings $settings;

    private SettingsSectionRegistry $registry;

    private string $token;

    private SeedFixtureSection $section;

    protected function setUp(): void
    {
        $this->token              = 'tok-' . bin2hex(random_bytes(12));
        $this->repository         = new InMemorySettingValueRepository();
        $this->dispatcher         = new RecordingDispatcher();
        $this->section            = new SeedFixtureSection();
        $this->registry           = new SettingsSectionRegistry([new MailerFixtureSection(), $this->section]);
        $this->settings           = new InstanceSettings($this->repository, $this->registry, dispatcher: $this->dispatcher);
        $this->section->fallbacks = [
            SettingFallback::plain('host', 'relay.example.test'),
            SettingFallback::plain('port', '2525'),
            SettingFallback::secret('token', $this->token),
            SettingFallback::plain('enabled', null),
        ];
    }

    protected function tearDown(): void
    {
        $this->section->fallbacks = [];
    }

    public function testSeedsOnceAuditsAndIsIdempotent(): void
    {
        $logger = $this->logger();
        $seeder = new FallbackSeeder($this->registry, $this->repository, $this->settings, logger: $logger, dispatcher: $this->dispatcher);

        $report = $seeder->seed();
        self::assertSame(['relay'], $report->sections, 'only sections exposing fallbacks are examined');
        self::assertSame(['relay.host', 'relay.port', 'relay.token'], $report->seeded);
        self::assertSame(['relay.token'], $report->secrets);
        self::assertSame(['relay.enabled' => SeedReport::SKIP_EMPTY], $report->skipped);
        self::assertTrue($report->hasChanges());
        self::assertFalse($report->dryRun);
        self::assertSame(1, $this->repository->transactions);
        self::assertSame('relay.example.test', $this->settings->get('relay.host'));
        self::assertSame(2525, $this->settings->getInt('relay.port'));
        self::assertSame($this->token, $this->settings->getSecret('relay.token'));

        $event = $this->dispatcher->events[0];
        self::assertInstanceOf(SettingsChangedEvent::class, $event);
        self::assertSame(SettingsChangedEvent::ORIGIN_SEED, $event->origin);
        self::assertSame(['token'], $event->secretFields);

        $second = $seeder->seed();
        self::assertSame([], $second->seeded);
        self::assertSame(3, $second->skippedCount(SeedReport::SKIP_STORED));
        self::assertSame(4, $second->skippedCount());
        self::assertCount(1, $this->dispatcher->events, 'a second run writes nothing');

        self::assertStringNotContainsString($this->token, var_export($logger->records, true), 'no value in the log');
        self::assertStringNotContainsString($this->token, print_r($report, true));
        self::assertSame(['Instance settings saved.', 'Instance settings seeded from fallbacks.'], [$logger->records[0][0], $logger->records[1][0]]);
        self::assertSame('relay.host,relay.port,relay.token', $logger->records[1][1]['seeded']);
    }

    public function testNeverOverwritesAStoredValue(): void
    {
        $this->settings->save('relay', ['host' => 'kept.example.test']);
        $report = (new FallbackSeeder($this->registry, $this->repository, $this->settings, dispatcher: $this->dispatcher))->seed(['relay']);

        self::assertSame('relay.host', array_search(SeedReport::SKIP_STORED, $report->skipped, true));
        self::assertSame('kept.example.test', $this->settings->get('relay.host'));
    }

    public function testDryRunWritesNothing(): void
    {
        $report = (new FallbackSeeder($this->registry, $this->repository, $this->settings, dispatcher: $this->dispatcher))->seed(null, true);

        self::assertTrue($report->dryRun);
        self::assertSame(['relay.host', 'relay.port', 'relay.token'], $report->seeded);
        self::assertSame([], $this->repository->rows);
        self::assertSame(0, $this->repository->transactions);
        self::assertSame([], $this->dispatcher->events);
    }

    public function testRefusedFallbacksAreReportedWithoutValues(): void
    {
        $this->section->fallbacks = [
            SettingFallback::plain('port', 'not-a-number'),
            SettingFallback::plain('missing', 'x'),
            SettingFallback::plain('token', $this->token),
            SettingFallback::plain('host', '  '),
            SettingFallback::plain('enabled', true),
            SettingFallback::plain('enabled', false),
        ];
        $report = (new FallbackSeeder($this->registry, $this->repository, $this->settings, dispatcher: $this->dispatcher))->seed(['relay']);

        self::assertSame([
            'relay.port'    => SeedReport::SKIP_INVALID,
            'relay.missing' => SeedReport::SKIP_UNKNOWN_FIELD,
            'relay.token'   => SeedReport::SKIP_SECRET_MISMATCH,
            'relay.host'    => SeedReport::SKIP_EMPTY,
            'relay.enabled' => SeedReport::SKIP_DUPLICATE,
        ], $report->skipped);
        self::assertSame(['relay.enabled'], $report->seeded);
        self::assertSame([], $report->secrets);
        self::assertNull($this->settings->getSecret('relay.token'), 'a secret is never stored in clear');
    }

    public function testSectionsWithoutFallbacksAndUnknownSections(): void
    {
        $seeder = new FallbackSeeder($this->registry, $this->repository, $this->settings);
        $report = $seeder->seed(['mailer']);
        self::assertSame([], $report->sections);
        self::assertFalse($report->hasChanges());

        $this->expectException(UnknownSettingException::class);
        $seeder->seed(['nope']);
    }

    public function testPhantomTransactionIsRefusedBeforeWriting(): void
    {
        $native = $this->createMock(PDO::class);
        $native->method('inTransaction')->willReturn(false);
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(2);
        $connection->method('getNativeConnection')->willReturn($native);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->with('settings')->willReturn($entityManager);

        $seeder = new FallbackSeeder($this->registry, $this->repository, $this->settings, transactionGuard: new TransactionStateGuard($doctrine, 'settings'));
        self::assertTrue($seeder->seed(null, true)->hasChanges(), 'a dry run does not need a transaction');

        try {
            $seeder->seed();
            self::fail('phantom transaction not detected');
        } catch (LogicException $e) {
            self::assertStringContainsString('isTransactional(): false', $e->getMessage());
        }
        self::assertSame([], $this->repository->rows);
        self::assertSame(0, $this->repository->transactions);
    }

    public function testAConcurrentAdminSaveWinsAndTheSeedRetries(): void
    {
        $repository = new RacingRepository($this->repository, new SettingValue('relay', 'host', new DateTimeImmutable()));
        $settings   = new InstanceSettings($repository, $this->registry);
        $report     = (new FallbackSeeder($this->registry, $repository, $settings, dispatcher: $this->dispatcher))->seed(['relay']);

        self::assertSame(2, $repository->attempts, 'the unique violation rolled back the first attempt');
        self::assertSame(['relay.port', 'relay.token'], $report->seeded);
        self::assertSame(SeedReport::SKIP_STORED, $report->skipped['relay.host']);
        self::assertSame('admin.example.test', $settings->get('relay.host'), 'the concurrent value is never overwritten');
        self::assertCount(1, $this->dispatcher->events);
    }

    public function testAPersistentUniqueViolationIsRethrown(): void
    {
        $repository        = new RacingRepository($this->repository, new SettingValue('relay', 'host', new DateTimeImmutable()));
        $repository->races = 5;

        try {
            (new FallbackSeeder($this->registry, $repository, new InstanceSettings($repository, $this->registry), dispatcher: $this->dispatcher))->seed(['relay']);
            self::fail('expected the unique violation');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(2, $repository->attempts);
        }
        self::assertSame([], $this->dispatcher->events);
    }

    public function testAuditEventsAreDispatchedOnlyAfterTheCommit(): void
    {
        $second               = new SeedFixtureSection([SettingFallback::plain('host', 'two.example.test')], name: 'relay_two');
        $registry             = new SettingsSectionRegistry([$this->section, $second]);
        $failing              = new RacingRepository($this->repository, null);
        $failing->failOnApply = 2;

        try {
            (new FallbackSeeder($registry, $failing, new InstanceSettings($failing, $registry), dispatcher: $this->dispatcher))->seed();
            self::fail('expected the write failure');
        } catch (RuntimeException $e) {
            self::assertSame('disk full', $e->getMessage());
        }
        self::assertSame([], $this->dispatcher->events, 'a rolled-back seed dispatches nothing');
        self::assertSame([], $this->repository->rows);

        $dispatcher = new RecordingDispatcher();
        $report     = (new FallbackSeeder($registry, $this->repository, $this->settings, dispatcher: $dispatcher))->seed();
        self::assertSame(['relay.host', 'relay.port', 'relay.token', 'relay_two.host'], $report->seeded);
        self::assertSame(['relay', 'relay_two'], array_map(static fn (object $e): string => $e instanceof SettingsChangedEvent ? $e->section : '', $dispatcher->events));
    }

    /**
     * @return AbstractLogger&object{records: list<array{string, array<string, mixed>}>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{string, array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
    }
}
