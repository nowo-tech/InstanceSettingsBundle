<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\SectionTest;

use Closure;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Event\SettingsTestedEvent;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsGroup;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestContext;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTesterRegistry;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestRunner;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestStore;
use Nowo\InstanceSettingsBundle\SectionTest\TestCheck;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;
use Nowo\InstanceSettingsBundle\SectionTest\TestStatus;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\AllowAllInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\ConfigurableInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest\CalendarOAuthFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\ClosureTester;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingDispatcher;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;
use Nowo\InstanceSettingsBundle\Tests\Support\UniqueViolationRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Validator\Validation;
use Throwable;

use function in_array;

/**
 * 1.18: the server side of the Test button — permissions, a registered tester, rate limit, one test at a time, a closed
 * context after the call, the leak guard, the stored report, the event and the log lines.
 */
final class SectionTestRunnerTest extends TestCase
{
    /** A throwaway value, never a real secret. */
    private const SECRET = 'Gz4Kp9Wq2Ls7';

    private InMemorySettingValueRepository $repository;

    private RecordingDispatcher $dispatcher;

    private RecordingLogger $logger;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->repository = new InMemorySettingValueRepository();
        $this->dispatcher = new RecordingDispatcher();
        $this->logger     = new RecordingLogger();
        $this->clock      = new MockClock('2026-11-14 10:00:00');
    }

    public function testARunOnTheStoredValuesWithAContextClosedAfterwards(): void
    {
        $settings = $this->settings();
        $settings->save('calendar_oauth', ['google_client_id' => 'client-1', 'google_client_secret' => self::SECRET]);
        $kept   = null;
        $runner = $this->runner($settings, ['google' => static function (SectionTestContext $context) use (&$kept): TestReport {
            $kept = $context;
            self::assertSame('client-1', $context->string('google_client_id'));
            self::assertSame(self::SECRET, $context->secret('google_client_secret'));
            self::assertSame(['mode', 'google_client_id', 'google_client_secret'], $context->groupFields());

            return TestReport::of(TestCheck::ok('a.b', 'c.d', ['%client%' => 'client-1']));
        }], $this->userGuard(['ROLE_ADMIN']));

        $report = $runner->run('calendar_oauth', 'google');

        self::assertSame(TestStatus::Ok, $report->status);
        self::assertSame('user', $report->actor);
        self::assertSame('google', $report->group);
        self::assertSame('2026-11-14 10:00:00', $report->testedAt->format('Y-m-d H:i:s'));
        self::assertInstanceOf(SectionTestContext::class, $kept);
        self::assertFalse($kept->isOpen(), 'closed once the tester returned');
        try {
            $kept->secret('google_client_secret');
            self::fail('a kept context never reads a secret again');
        } catch (LogicException) {
        }
        self::assertCount(1, $this->dispatcher->events);
        $event = $this->dispatcher->events[0];
        self::assertInstanceOf(SettingsTestedEvent::class, $event);
        self::assertSame(['calendar_oauth', 'google', TestStatus::Ok, 'user'], [$event->section, $event->group, $event->status, $event->actor]);
        self::assertStringContainsString(SectionTestRunner::LOG_TESTED, $this->logger->dump());
        self::assertStringNotContainsString(self::SECRET, $this->logger->dump());
        self::assertArrayHasKey('google', $runner->lastReports('calendar_oauth'));
        self::assertInstanceOf(SettingsGroup::class, $runner->group('calendar_oauth', 'google'));
        self::assertNull($runner->group('calendar_oauth', null));
        self::assertSame([], $this->rows(SectionTestStore::LOCKS), 'the running marker is gone');
    }

    public function testOneTestAtATimePerSectionAndActor(): void
    {
        $inner  = null;
        $runner = null;
        $runner = $this->runner($this->settings(), [
            '' => static function () use (&$runner, &$inner): TestReport {
                try {
                    $runner?->run('calendar_oauth', 'google');
                } catch (Throwable $e) {
                    $inner = $e;
                }

                return TestReport::of(TestCheck::ok('a.b'));
            },
            'google' => static fn (): TestReport => TestReport::of(TestCheck::ok('a.b')),
        ]);

        self::assertSame(TestStatus::Ok, $runner->run('calendar_oauth', null)->status);
        self::assertInstanceOf(ConflictHttpException::class, $inner, 'a second test of the same section by the same actor while the first runs');
        self::assertStringContainsString(SectionTestRunner::REASON_BUSY, $this->logger->dump());
        self::assertSame(TestStatus::Ok, $runner->run('calendar_oauth', 'google')->status, 'free again afterwards');
    }

    public function testAThrowingTesterBecomesAFailedCheck(): void
    {
        $runner = $this->runner($this->settings(), ['' => static fn (): TestReport => throw new RuntimeException('connection refused')]);

        $report = $runner->run('calendar_oauth', null);

        self::assertSame(TestStatus::Fail, $report->status);
        self::assertSame(SectionTestRunner::FAILED_LABEL, $report->checks[0]->labelKey);
        self::assertSame(SectionTestRunner::FAILED_HINT, $report->checks[0]->hintKey);
        self::assertStringContainsString('connection refused', $this->logger->dump(), 'a message without a secret is logged as is (debug)');
        self::assertSame('debug', $this->logger->records[0]['level'] ?? null);
    }

    public function testTheBuiltInLimitIsPerActorAndClientIp(): void
    {
        $runner = $this->runner($this->settings(), ['' => static fn (): TestReport => new TestReport()], new AccessGuard(new AllowAllInstanceSettingsAccessChecker(), true), $stack = new RequestStack());
        $stack->push(Request::create('/', server: ['REMOTE_ADDR' => '198.51.100.1']));
        for ($i = 0; $i < SectionTestRunner::DEFAULT_LIMIT; ++$i) {
            self::assertNull($runner->run('calendar_oauth', null)->actor);
        }
        try {
            $runner->run('calendar_oauth', null);
            self::fail('the eleventh test of a caller is refused');
        } catch (TooManyRequestsHttpException $e) {
            self::assertSame(SectionTestRunner::DEFAULT_INTERVAL, (int) $e->getHeaders()['Retry-After']);
        }

        $stack->pop();
        $stack->push(Request::create('/', server: ['REMOTE_ADDR' => '198.51.100.2']));
        self::assertSame(TestStatus::Skipped, $runner->run('calendar_oauth', null)->status, 'another client IP has its own window');
    }

    public function testAConfiguredRateLimiter(): void
    {
        $limiter = new RateLimiterFactory(['id' => 'tests', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage());
        $runner  = $this->runner($this->settings(), ['' => static fn (): TestReport => new TestReport()], rateLimiter: $limiter);

        $runner->run('calendar_oauth', null);
        $this->expectException(TooManyRequestsHttpException::class);
        $runner->run('calendar_oauth', null);
    }

    public function testATesterOverItsBudgetIsLogged(): void
    {
        $clock  = $this->clock;
        $runner = $this->runner($this->settings(), ['' => static function () use ($clock): TestReport {
            $clock->sleep(11);

            return new TestReport();
        }]);

        $runner->run('calendar_oauth', null);

        self::assertStringContainsString(SectionTestRunner::LOG_OVER_BUDGET, $this->logger->dump());
    }

    public function testPermissions(): void
    {
        $tests = ['' => static fn (): TestReport => new TestReport(), 'google' => static fn (): TestReport => new TestReport()];

        self::assertSame([null, 'google'], $this->runner($this->settings(), $tests, $this->userGuard(['ROLE_ADMIN']))->testableGroups('calendar_oauth'));
        self::assertSame([], $this->runner($this->settings(), $tests, $this->userGuard(['ROLE_USER']))->testableGroups('calendar_oauth'));
        self::assertSame([], $this->runner($this->settings(), [], $this->userGuard(['ROLE_ADMIN']))->testableGroups('calendar_oauth'), 'no tester at all');
        self::assertSame([], $this->runner($this->settings(), ['google' => static fn (): TestReport => new TestReport()], $this->userGuard(['ROLE_ADMIN']))->testableGroups('general'));

        // security.test_roles set: the test area is asked too.
        self::assertFalse($this->runner($this->settings(), $tests, $this->userGuard(['ROLE_ADMIN'], ['ROLE_OPS']), testArea: true)->canTest('calendar_oauth'));
        self::assertTrue($this->runner($this->settings(), $tests, $this->userGuard(['ROLE_ADMIN', 'ROLE_OPS'], ['ROLE_OPS']), testArea: true)->canTest('calendar_oauth'));
        self::assertTrue($this->runner($this->settings(), $tests, $this->userGuard(['ROLE_ADMIN'], ['ROLE_OPS']))->canTest('calendar_oauth'), 'without test_roles the area is not asked');

        $this->expectException(AccessDeniedHttpException::class);
        $this->runner($this->settings(), $tests, $this->userGuard(['ROLE_USER']))->run('calendar_oauth', null);
    }

    public function testATesterMissingFromTheLocatorIsNotFound(): void
    {
        $settings = $this->settings();
        $registry = new SettingsSectionRegistry([new CalendarOAuthFixtureSection()]);
        $runner   = new SectionTestRunner(
            new SectionTesterRegistry([['section' => 'calendar_oauth', 'group' => null, 'id' => 'gone']], null, $registry),
            $registry,
            $settings,
            new AccessGuard(new AllowAllInstanceSettingsAccessChecker(), true),
            new SectionTestStore($this->repository),
        );

        $this->expectException(NotFoundHttpException::class);
        $runner->run('calendar_oauth', null);
    }

    public function testWithoutLoggerDispatcherOrClockAndWhenTheFirstReportRaces(): void
    {
        $registry = new SettingsSectionRegistry([new CalendarOAuthFixtureSection()]);
        $settings = new InstanceSettings($this->repository, $registry, new ValueNormalizer(Validation::createValidator()));
        $inner    = $this->repository;
        // A store whose report insert loses the race against a concurrent request (the markers still work).
        $racing = new class($inner) implements SettingValueRepositoryInterface {
            public function __construct(private readonly InMemorySettingValueRepository $inner)
            {
            }

            public function findAll(): array
            {
                return $this->inner->findAll();
            }

            public function findBySection(string $section): array
            {
                return $this->inner->findBySection($section);
            }

            public function apply(array $save, array $remove): void
            {
                foreach ($save as $row) {
                    if ($row instanceof SettingValue && $row->getSection() === SectionTestStore::REPORTS) {
                        (new UniqueViolationRepository())->apply($save, $remove);
                    }
                }
                $this->inner->apply($save, $remove);
            }

            public function transactional(callable $callback): mixed
            {
                return $this->inner->transactional($callback);
            }
        };
        $runner = new SectionTestRunner(
            new SectionTesterRegistry([['section' => 'calendar_oauth', 'group' => null, 'id' => 't']], new ServiceLocator(['t' => static fn (): ClosureTester => new ClosureTester(static fn (): TestReport => TestReport::of(TestCheck::ok('a.b')))]), $registry),
            $registry,
            $settings,
            new AccessGuard(new AllowAllInstanceSettingsAccessChecker(), true),
            new SectionTestStore($racing),
        );

        self::assertSame(TestStatus::Ok, $runner->run('calendar_oauth', null)->status);
        self::assertSame([], $runner->lastReports('calendar_oauth'), 'the other request\'s report stays');
    }

    /**
     * @param array<string, Closure(SectionTestContext): TestReport> $tests group code ('' = the section) => test
     */
    private function runner(InstanceSettings $settings, array $tests, ?AccessGuard $guard = null, ?RequestStack $stack = null, ?RateLimiterFactory $rateLimiter = null, bool $testArea = false): SectionTestRunner
    {
        $registry  = new SettingsSectionRegistry([new CalendarOAuthFixtureSection()]);
        $declared  = [];
        $factories = [];
        foreach ($tests as $group => $test) {
            $id             = 'tester.' . $group;
            $declared[]     = ['section' => 'calendar_oauth', 'group' => $group === '' ? null : $group, 'id' => $id];
            $factories[$id] = static fn (): ClosureTester => new ClosureTester($test);
        }

        return new SectionTestRunner(
            new SectionTesterRegistry($declared, new ServiceLocator($factories), $registry),
            $registry,
            $settings,
            $guard ?? $this->userGuard(['ROLE_ADMIN']),
            new SectionTestStore($this->repository),
            $testArea,
            $rateLimiter,
            $this->dispatcher,
            $this->logger,
            $this->clock,
            'test-salt',
            $stack,
        );
    }

    private function settings(): InstanceSettings
    {
        return new InstanceSettings($this->repository, new SettingsSectionRegistry([new CalendarOAuthFixtureSection()]), new ValueNormalizer(Validation::createValidator()));
    }

    /**
     * Guard of a user holding `$granted`, with the role-based checker (`_test` area roles when given).
     *
     * @param list<string> $granted
     * @param list<string>|null $testRoles
     */
    private function userGuard(array $granted, ?array $testRoles = null): AccessGuard
    {
        $authorization = new class($granted) implements AuthorizationCheckerInterface {
            /**
             * @param list<string> $granted
             */
            public function __construct(private readonly array $granted)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return in_array($attribute, $this->granted, true);
            }
        };
        $checker = new ConfigurableInstanceSettingsAccessChecker($authorization, ['ROLE_ADMIN'], $testRoles === null ? [] : [InstanceSettingsAccessCheckerInterface::AREA_TEST => $testRoles]);
        $request = new Request();
        $request->attributes->set('_firewall_context', 'main');
        $stack = new RequestStack();
        $stack->push($request);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('user', null, $granted), 'main', $granted));

        return new AccessGuard($checker, false, $storage, $stack);
    }

    /**
     * @return list<SettingValue>
     */
    private function rows(string $section): array
    {
        return $this->repository->findBySection($section);
    }
}
