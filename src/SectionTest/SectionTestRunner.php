<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Event\SettingsTestedEvent;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Section\SettingsGroup;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\InstanceSettingsBundle\Ui\SectionTestView;
use Nowo\UiKitBundle\Date\DateFormatterInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function array_keys;
use function array_map;
use function hash;
use function is_string;
use function max;

/**
 * Runs the tester of a settings section, or of one field group of it, against the STORED values (1.18): the server
 * side of the Test button.
 *
 * The checks, in order, are the UI gate, the section's own area and — when `security.test_roles` is set —
 * {@see InstanceSettingsAccessCheckerInterface::AREA_TEST}; a registered tester for exactly that section and group
 * (names matched byte for byte, REQ-SEC-006); the rate limit of the actor (`security.test_rate_limiter`, else the
 * built-in {@see self::DEFAULT_LIMIT} tests per {@see self::DEFAULT_INTERVAL} seconds kept in the bundle's store); and
 * one test at a time per section and actor. The endpoint checks the section's CSRF token in between.
 *
 * Then the tester runs with a {@see SectionTestContext} that reads the stored values (secrets decrypted on demand, only
 * until the tester returns). Any Throwable becomes a failed check with a generic hint; its message is logged at debug
 * level, redacted. The {@see LeakGuard} redacts every part of the report that matches a stored secret of the section,
 * and the redacted report is stored as the last report, logged (names and status) and announced with a
 * {@see SettingsTestedEvent}. Save never waits for any of this: the test is a separate request on the stored values.
 *
 * Stateless (REQ-RUNTIME-002): the actor, the request, the limiter and the store are read per call; the clock is
 * injected. Nothing about a test outlives the call except the stored report.
 */
final readonly class SectionTestRunner
{
    /** Built-in rate limit: tests per actor and window. */
    public const DEFAULT_LIMIT = 10;

    /** Built-in rate limit: window length, in seconds. */
    public const DEFAULT_INTERVAL = 600;

    /** A "test running" marker older than this (seconds) is from a request that died, and is taken over. */
    public const STALE_AFTER = 120;

    /** Log message of a test that ran (names and status only). */
    public const LOG_TESTED = 'Instance settings: section tested';

    /** Log message of a refused test (names and reason only). */
    public const LOG_REFUSED = 'Instance settings: section test refused';

    /** Debug log message of a tester that threw (exception class and redacted message). */
    public const LOG_TESTER_FAILED = 'Instance settings: section tester threw';

    /** Log message of a report in which the leak guard redacted something. */
    public const LOG_REDACTED = 'Instance settings: section test report redacted';

    /** Log message of a tester that ran over its time budget. */
    public const LOG_OVER_BUDGET = 'Instance settings: section tester ran over its time budget';

    /** Refusal reason: rate limited. */
    public const REASON_RATE_LIMITED = 'rate_limited';

    /** Refusal reason: a test of that section by that actor is already running. */
    public const REASON_BUSY = 'busy';

    /** Label key (bundle domain) of the check standing for a tester that threw. */
    public const FAILED_LABEL = 'test.check.tester';

    /** Hint key (bundle domain) of the check standing for a tester that threw. */
    public const FAILED_HINT = 'test.hint.exception';

    /** Limiter bucket of the tests (salted apart from the reveal buckets). */
    private const BUCKET = 'section_test';

    /**
     * @param SectionTesterRegistry $testers the registered testers
     * @param SettingsSectionRegistry $registry the sections and their fields
     * @param InstanceSettingsInterface $settings reads the stored values (secrets decrypted on demand)
     * @param AccessGuard $guard current user and the access checker
     * @param SectionTestStore $store last reports, running markers and the built-in rate windows
     * @param bool $testArea `security.test_roles` is set: {@see InstanceSettingsAccessCheckerInterface::AREA_TEST} is asked too
     * @param RateLimiterFactoryInterface|null $rateLimiter `security.test_rate_limiter` (null = the built-in limit)
     * @param EventDispatcherInterface|null $dispatcher receives the {@see SettingsTestedEvent}
     * @param LoggerInterface|null $logger one line per test and per refusal (names only, never a value)
     * @param ClockInterface|null $clock time of the test (null = the system clock)
     * @param string $keySalt `%kernel.secret%`: salts the actor key of the limiter and the running marker
     * @param RequestStack|null $requestStack client IP of a caller the host cannot name (its actor key)
     * @param TranslatorInterface|null $translator renders the checks, so the leak guard also sees the final texts
     */
    public function __construct(
        private SectionTesterRegistry $testers,
        private SettingsSectionRegistry $registry,
        private InstanceSettingsInterface $settings,
        private AccessGuard $guard,
        private SectionTestStore $store,
        private bool $testArea = false,
        private ?RateLimiterFactoryInterface $rateLimiter = null,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?LoggerInterface $logger = null,
        private ?ClockInterface $clock = null,
        private string $keySalt = '',
        private ?RequestStack $requestStack = null,
        private ?TranslatorInterface $translator = null,
        private ?DateFormatterInterface $dates = null,
    ) {
    }

    /**
     * Whether the current user may test `$section`: the UI gate, the section's area and, with `security.test_roles`, the
     * test area.
     */
    public function canTest(string $section): bool
    {
        return $this->guard->isGranted()
            && $this->guard->isGranted($section)
            && (!$this->testArea || $this->guard->isGranted(InstanceSettingsAccessCheckerInterface::AREA_TEST));
    }

    /**
     * The tested groups of `$section` the current user may run (null = the section tester); empty without a tester or
     * without the permission.
     *
     * @return list<string|null>
     */
    public function testableGroups(string $section): array
    {
        if ($this->testers->isEmpty()) {
            return [];
        }
        $groups = $this->testers->groupsOf($section);

        return $groups === [] || !$this->canTest($section) ? [] : $groups;
    }

    /**
     * The field group `$group` of `$section` (its label and resolved fields), or null for the section tester.
     */
    public function group(string $section, ?string $group): ?SettingsGroup
    {
        return $this->testers->group($section, $group);
    }

    /**
     * The last report of every tested group of `$section` ('' = the section tester).
     *
     * @return array<string, StoredTestReport>
     */
    public function lastReports(string $section): array
    {
        return $this->store->lastReports($section);
    }

    /**
     * Refuses unless the current user may test `$section` / `$group`: the roles first, then a registered tester.
     *
     * @throws NotFoundHttpException no tester for exactly that section and group
     */
    public function assertTestable(string $section, ?string $group): void
    {
        if (!$this->canTest($section)) {
            $this->guard->deny('Access denied to testing this settings section.');
        }
        if (!$this->testers->has($section, $group)) {
            throw new NotFoundHttpException('No tester for this settings section or group.');
        }
    }

    /**
     * Runs the tester of `$section` / `$group` and returns its stored (redacted) report.
     *
     * @throws NotFoundHttpException no tester for exactly that section and group
     * @throws TooManyRequestsHttpException the rate limit refused the call
     * @throws ConflictHttpException a test of this section by this actor is already running
     */
    public function run(string $section, ?string $group): StoredTestReport
    {
        $this->assertTestable($section, $group);
        $tester = $this->testers->get($section, $group);
        if (!$tester instanceof SettingsSectionTesterInterface) {
            throw new NotFoundHttpException('No tester for this settings section or group.');
        }
        $key = $this->actorKey();
        $this->consumeRateLimit($key, $section, $group);
        $marker = $this->store->acquire($key, $section, $this->now(), self::STALE_AFTER);
        if (!$marker instanceof SectionTestStateRow) {
            $this->refused($section, $group, self::REASON_BUSY);

            throw new ConflictHttpException('A test of this settings section is already running.');
        }

        try {
            $report          = $this->execute($tester, $section, $group);
            $secrets         = $this->secrets($section);
            [$report, $hits] = LeakGuard::report($report, $secrets);
            // Audit PU-L1: the translated texts too (a secret split over parameters is whole once rendered).
            [$report, $rendered] = $this->guardRendered($report, $this->registry->get($section)->getTranslationDomain(), $secrets);
            $hits += $rendered;
            if ($hits > 0) {
                $this->logger?->warning(self::LOG_REDACTED, ['section' => $section, 'group' => $group, 'hits' => $hits]);
            }
            $stored = StoredTestReport::of($section, $group, $report, $this->now(), $this->actor());
            $this->store->saveReport($stored);
            $this->logger?->info(self::LOG_TESTED, [
                'section' => $section,
                'group'   => $group,
                'status'  => $stored->status->value,
                'actor'   => $stored->actor,
                'at'      => $stored->testedAt->format(DateTimeImmutable::ATOM),
            ]);
            $this->dispatcher?->dispatch(new SettingsTestedEvent($section, $group, $stored->status, $stored->actor, $stored->testedAt));

            return $stored;
        } finally {
            $this->store->release($marker);
        }
    }

    /**
     * The translated view of a stored report ({@see SectionTestView::json()}) with every final text checked against the
     * section's secrets as stored now (audit PU-L1): a check whose label or hint is a hit shows the generic redacted
     * texts, a summary that is a hit shows {@see LeakGuard::REDACTED}.
     *
     * @param string $domain translation domain of the section
     *
     * @return array{section: string, group: string|null, status: string, statusLabel: string, testedAt: string, time: string, summary: string, checks: list<array{status: string, statusLabel: string, label: string, hint: string|null}>}
     */
    public function view(StoredTestReport $report, string $domain): array
    {
        $guard = LeakGuard::of($this->secrets($report->section));
        $view  = SectionTestView::json($report, $domain, $this->translator, $this->dates);
        foreach ($view['checks'] as $index => $check) {
            if ($guard->matches($check['label']) || ($check['hint'] !== null && $guard->matches($check['hint']))) {
                $view['checks'][$index]['label'] = $this->trans(LeakGuard::REDACTED_LABEL, [], NowoInstanceSettingsBundle::TRANSLATION_DOMAIN);
                $view['checks'][$index]['hint']  = $this->trans(LeakGuard::REDACTED_HINT, [], NowoInstanceSettingsBundle::TRANSLATION_DOMAIN);
            }
        }
        if ($guard->matches($view['summary'])) {
            $view['summary'] = LeakGuard::REDACTED;
        }

        return $view;
    }

    /**
     * Replaces every check whose translated label or hint holds a secret by the generic redacted check.
     *
     * @param list<string> $secrets
     *
     * @return array{0: TestReport, 1: int}
     */
    private function guardRendered(TestReport $report, string $domain, #[SensitiveParameter] array $secrets): array
    {
        $guard  = LeakGuard::of($secrets);
        $hits   = 0;
        $checks = [];
        foreach ($report->checks as $check) {
            $checkDomain = $check->domain ?? $domain;
            $label       = $this->trans($check->labelKey, [], $checkDomain);
            $hint        = $check->hintKey === null ? '' : $this->trans($check->hintKey, SectionTestView::params($check->hintParams), $checkDomain);
            if ($guard->matches($label) || $guard->matches($hint)) {
                ++$hits;
                $check = new TestCheck(LeakGuard::REDACTED_LABEL, $check->status, LeakGuard::REDACTED_HINT, [], NowoInstanceSettingsBundle::TRANSLATION_DOMAIN);
            }
            $checks[] = $check;
        }

        return [$hits === 0 ? $report : new TestReport($checks), $hits];
    }

    /**
     * @param array<string, float|int|string> $params
     */
    private function trans(string $key, array $params, string $domain): string
    {
        return $this->translator instanceof TranslatorInterface ? $this->translator->trans($key, $params, $domain) : $key;
    }

    /**
     * Runs the tester with a context that stops answering when it returns; a Throwable becomes a failed check.
     */
    private function execute(SettingsSectionTesterInterface $tester, string $section, ?string $group): TestReport
    {
        $fields  = $this->registry->fields($section);
        $secrets = [];
        foreach ($fields as $name => $field) {
            if ($field->secret) {
                $secrets[] = (string) $name;
            }
        }
        $resolved = $this->testers->group($section, $group);
        $settings = $this->settings;
        $context  = new SectionTestContext(
            $section,
            $group,
            array_map('strval', array_keys($fields)),
            $secrets,
            $resolved->fields ?? array_map('strval', array_keys($fields)),
            static fn (string $field, bool $secret): bool|float|int|string|null => $secret ? $settings->getSecret($section . '.' . $field) : $settings->get($section . '.' . $field),
        );
        $started = $this->now();

        try {
            $report = $tester->test($context);
        } catch (Throwable $e) {
            $this->logger?->debug(self::LOG_TESTER_FAILED, [
                'section'   => $section,
                'group'     => $group,
                'exception' => $e::class,
                'message'   => LeakGuard::text($e->getMessage(), $this->secrets($section)),
            ]);
            $report = TestReport::of(TestCheck::fail(self::FAILED_LABEL, self::FAILED_HINT, [], NowoInstanceSettingsBundle::TRANSLATION_DOMAIN));
        } finally {
            $context->close();
        }
        $elapsed = $this->now()->getTimestamp() - $started->getTimestamp();
        if ($elapsed > SettingsSectionTesterInterface::TIME_BUDGET_SECONDS) {
            $this->logger?->warning(self::LOG_OVER_BUDGET, ['section' => $section, 'group' => $group, 'seconds' => $elapsed, 'budget' => SettingsSectionTesterInterface::TIME_BUDGET_SECONDS]);
        }

        return $report;
    }

    /**
     * The stored secrets of the section (decrypted, for the leak guard only; never kept).
     *
     * @return list<string>
     */
    private function secrets(string $section): array
    {
        $secrets = [];
        foreach ($this->registry->fields($section) as $name => $field) {
            $value = $field->secret ? $this->settings->getSecret($section . '.' . $name) : null;
            if (is_string($value) && $value !== '') {
                $secrets[] = $value;
            }
        }

        return $secrets;
    }

    /**
     * @throws TooManyRequestsHttpException
     */
    private function consumeRateLimit(string $key, string $section, ?string $group): void
    {
        if ($this->rateLimiter instanceof RateLimiterFactoryInterface) {
            $limit      = $this->rateLimiter->create($key)->consume();
            $retryAfter = $limit->isAccepted() ? null : max(1, $limit->getRetryAfter()->getTimestamp() - $this->now()->getTimestamp());
        } else {
            $retryAfter = $this->store->consume($key, self::DEFAULT_LIMIT, self::DEFAULT_INTERVAL, $this->now());
        }
        if ($retryAfter !== null) {
            $this->refused($section, $group, self::REASON_RATE_LIMITED);

            throw new TooManyRequestsHttpException($retryAfter, 'Too many section tests.');
        }
    }

    private function refused(string $section, ?string $group, string $reason): void
    {
        $this->logger?->info(self::LOG_REFUSED, ['section' => $section, 'group' => $group, 'reason' => $reason, 'actor' => $this->actor()]);
    }

    /**
     * User identifier of the authenticated user, or null.
     */
    private function actor(): ?string
    {
        $user = $this->guard->user();

        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
    }

    /**
     * Salted SHA-256 of the actor (its user identifier, else its client IP, else `anonymous`): the key of its rate
     * window and of its running marker, so no user identifier reaches the store or the limiter (as the reveal, audit F5).
     */
    private function actorKey(): string
    {
        $actor = $this->actor();
        if ($actor === null) {
            $ip    = $this->requestStack?->getMainRequest()?->getClientIp();
            $actor = $ip === null ? 'anonymous' : 'ip:' . $ip;
        }

        return hash('sha256', $this->keySalt . "\2" . self::BUCKET . "\0" . $actor);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? (new NativeClock())->now();
    }
}
