<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Secret;

use Nowo\InstanceSettingsBundle\Event\SecretRevealedEvent;
use Nowo\InstanceSettingsBundle\Secret\SecretRevealer;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\AllowAllInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\ConfigurableInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\RevealFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingDispatcher;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
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

use function dirname;
use function file_get_contents;
use function in_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.15: the server-side checks and the audit entry of a secret reveal.
 */
final class SecretRevealerTest extends TestCase
{
    /** A throwaway value, never a real secret. */
    private const SECRET = 'placeholder-client-secret';

    private InMemorySettingValueRepository $repository;

    private RecordingDispatcher $dispatcher;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->repository = new InMemorySettingValueRepository();
        $this->dispatcher = new RecordingDispatcher();
        $this->logger     = new RecordingLogger();
    }

    public function testRevealReturnsTheSecretAndAuditsNamesOnly(): void
    {
        $settings = $this->settings();
        $settings->save('oauth', ['client_secret' => self::SECRET]);
        $revealer = $this->revealer($settings);

        self::assertSame(self::SECRET, $revealer->reveal('oauth', 'client_secret'));
        self::assertCount(1, $this->dispatcher->events);
        $event = $this->dispatcher->events[0];
        self::assertInstanceOf(SecretRevealedEvent::class, $event);
        self::assertSame('oauth.client_secret', $event->key());
        self::assertSame('oauth', $event->section);
        self::assertSame('client_secret', $event->field);
        self::assertTrue($event->stored);
        self::assertNull($event->actor, 'no resolvable user (allow_unauthenticated)');
        self::assertSame('2026-11-13T09:00:00+00:00', $event->revealedAt->format('c'));

        $records = $this->logger->records;
        self::assertCount(1, $records);
        self::assertSame('info', $records[0]['level']);
        self::assertSame(SecretRevealer::LOG_REVEALED, $records[0]['message']);
        self::assertSame('oauth.client_secret', $records[0]['context']['key']);
        self::assertStringNotContainsString(self::SECRET, $this->logger->dump(), 'REQ-OBS-001: never the value');
    }

    public function testAnAllowedRevealOfAnEmptyFieldIsAuditedAsNotStored(): void
    {
        $revealer = $this->revealer($this->settings());

        self::assertNull($revealer->reveal('oauth', 'client_secret'));
        $event = $this->dispatcher->events[0];
        self::assertInstanceOf(SecretRevealedEvent::class, $event);
        self::assertFalse($event->stored);
    }

    public function testTheActorOfTheAuditEntryIsTheAuthenticatedUser(): void
    {
        $settings = $this->settings();
        $settings->save('oauth', ['client_secret' => self::SECRET]);
        $request = new Request();
        $request->attributes->set('_firewall_context', 'main');
        $stack = new RequestStack();
        $stack->push($request);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('developer', null, ['ROLE_DEVELOPER']), 'main', ['ROLE_DEVELOPER']));
        $guard = new AccessGuard(new AllowAllInstanceSettingsAccessChecker(), true, $storage, $stack);

        self::assertSame(self::SECRET, $this->revealer($settings, $guard)->reveal('oauth', 'client_secret'));
        $event = $this->dispatcher->events[0];
        self::assertInstanceOf(SecretRevealedEvent::class, $event);
        self::assertSame('developer', $event->actor);
        self::assertSame('developer', $this->logger->records[0]['context']['actor']);
    }

    public function testWithoutTheRevealRolesNothingIsRevealed(): void
    {
        $settings = $this->settings();
        $settings->save('oauth', ['client_secret' => self::SECRET]);
        // The user opens the UI and the section, but holds no reveal role.
        $revealer = $this->revealer($settings, $this->roleGuard(['ROLE_ADMIN']));

        self::assertFalse($revealer->canReveal('oauth'));
        try {
            $revealer->reveal('oauth', 'client_secret');
            self::fail('a user without the reveal roles must be refused');
        } catch (AccessDeniedHttpException) {
            self::assertSame([], $this->dispatcher->events, 'a refusal is not a reveal');
            self::assertSame('warning', $this->logger->records[0]['level']);
            self::assertSame(SecretRevealer::REASON_FORBIDDEN, $this->logger->records[0]['context']['reason']);
            self::assertStringNotContainsString(self::SECRET, $this->logger->dump());
        }

        // The reveal role alone is not enough either: the section's own area still applies.
        self::assertFalse($this->revealer($settings, $this->roleGuard(['ROLE_DEVELOPER'], ['oauth' => ['ROLE_OPS']]))->canReveal('oauth'));
        self::assertTrue($this->revealer($settings, $this->roleGuard(['ROLE_ADMIN', 'ROLE_DEVELOPER']))->canReveal('oauth'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function refusedFields(): iterable
    {
        yield 'a secret without the opt-in' => ['oauth', 'signing_key'];
        yield 'a plain field' => ['oauth', 'client_id'];
        yield 'an unknown field' => ['oauth', 'client_secrets'];
        yield 'a look-alike field' => ['oauth', 'client_secret_'];
        yield 'another case' => ['oauth', 'Client_Secret'];
        yield 'a padded field name' => ['oauth', 'client_secret '];
        yield 'a field name with a newline' => ['oauth', "client_secret\n"];
        yield 'an unknown section' => ['oauth2', 'client_secret'];
        yield 'a padded section name' => ['oauth ', 'client_secret'];
    }

    #[DataProvider('refusedFields')]
    public function testOnlyTheExactlyNamedRevealableFieldIsRevealed(string $section, string $field): void
    {
        // REQ-SEC-006: the names from the client are compared byte for byte with the declared ones.
        $settings = $this->settings();
        $settings->save('oauth', ['client_secret' => self::SECRET, 'signing_key' => self::SECRET]);
        $revealer = $this->revealer($settings);

        self::assertFalse($revealer->isRevealable($section, $field));
        $this->expectException(NotFoundHttpException::class);
        $revealer->reveal($section, $field);
    }

    public function testTheRevealableFieldsOfASection(): void
    {
        $revealer = $this->revealer($this->settings());

        self::assertSame(['client_secret'], $revealer->revealableFields('oauth'));
        self::assertSame([], $revealer->revealableFields('oauth2'));
        self::assertTrue($revealer->isRevealable('oauth', 'client_secret'));
    }

    public function testTheRateLimiterRefusesTheSecondCall(): void
    {
        $settings = $this->settings();
        $settings->save('oauth', ['client_secret' => self::SECRET]);
        $factory  = new RateLimiterFactory(['id' => 'reveal', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage());
        $revealer = $this->revealer($settings, rateLimiter: $factory);

        self::assertSame(self::SECRET, $revealer->reveal('oauth', 'client_secret'));
        try {
            $revealer->reveal('oauth', 'client_secret');
            self::fail('the rate limiter must refuse the second call');
        } catch (TooManyRequestsHttpException $e) {
            self::assertSame(SecretRevealer::REASON_RATE_LIMITED, $this->logger->records[1]['context']['reason']);
            self::assertCount(1, $this->dispatcher->events, 'a refused call reveals nothing');
            self::assertNotSame('', (string) $e->getHeaders()['Retry-After']);
        }
    }

    public function testANonTextSecretIsRefusedInsteadOfDeliveredMangled(): void
    {
        // Audit F2: a stored secret that is not UTF-8 text cannot be delivered as text. Refusing is the only honest
        // answer, and the audit trail must then record a refusal, never a reveal.
        $settings = $this->settings();
        $settings->save('oauth', ['client_secret' => "binary-key-\xC3\x28-\xFF"]);
        $revealer = $this->revealer($settings);

        try {
            $revealer->reveal('oauth', 'client_secret');
            self::fail('a secret that is not text must be refused');
        } catch (ConflictHttpException $e) {
            self::assertStringNotContainsString('binary-key-', $e->getMessage());
        }
        self::assertSame([], $this->dispatcher->events, 'nothing left, so nothing is audited as revealed');
        self::assertSame(SecretRevealer::REASON_NOT_TEXT, $this->logger->records[0]['context']['reason']);
        self::assertStringNotContainsString('binary-key-', $this->logger->dump());
    }

    public function testCallersTheHostCannotNameDoNotShareOneRateLimitBucket(): void
    {
        // Audit F5: with no resolvable user the key is the client IP, so one caller cannot deny the feature to
        // everybody; the key is salted, so the limiter storage holds no plain hash of an identifier either.
        $settings = $this->settings();
        $settings->save('oauth', ['client_secret' => self::SECRET]);
        $storage = new InMemoryStorage();
        $factory = new RateLimiterFactory(['id' => 'reveal', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], $storage);

        self::assertSame(self::SECRET, $this->ipRevealer($settings, '203.0.113.9', $factory)->reveal('oauth', 'client_secret'));

        // The same unnamed shape from another address still has its own bucket.
        self::assertSame(self::SECRET, $this->ipRevealer($settings, '198.51.100.7', $factory)->reveal('oauth', 'client_secret'));

        // And the first address is now out of tokens.
        $this->expectException(TooManyRequestsHttpException::class);
        $this->ipRevealer($settings, '203.0.113.9', $factory)->reveal('oauth', 'client_secret');
    }

    public function testWithoutARequestTheUnnamedCallerFallsBackToOneBucket(): void
    {
        $settings = $this->settings();
        $settings->save('oauth', ['client_secret' => self::SECRET]);
        $factory = new RateLimiterFactory(['id' => 'reveal', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage());
        // No request stack at all (a CLI caller): the single `anonymous` bucket is the honest answer.
        $revealer = $this->revealer($settings, rateLimiter: $factory);

        self::assertSame(self::SECRET, $revealer->reveal('oauth', 'client_secret'));
        $this->expectException(TooManyRequestsHttpException::class);
        $revealer->reveal('oauth', 'client_secret');
    }

    public function testTheRateLimiterIsNotARuntimeDependency(): void
    {
        // The limiter is required by configuration as soon as a field is revealable, but symfony/rate-limiter must
        // stay out of `require`: a host that declares no revealable field installs nothing extra. The dev dependency
        // makes the limiter testable here, so this is the standing guard that it is not a runtime requirement.
        /** @var array{require: array<string, string>, require-dev: array<string, string>, suggest: array<string, string>} $composer */
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('symfony/rate-limiter', $composer['require']);
        self::assertArrayHasKey('symfony/rate-limiter', $composer['require-dev']);
        self::assertArrayHasKey('symfony/rate-limiter', $composer['suggest']);
    }

    /**
     * A revealer for a caller the host cannot name (`allow_unauthenticated`, or a custom checker without a Symfony
     * user) coming from `$ip`: the guard and the revealer share that request.
     */
    private function ipRevealer(InstanceSettings $settings, string $ip, RateLimiterFactory $rateLimiter): SecretRevealer
    {
        $request = Request::create('/admin/settings', server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->set('_firewall_context', 'main');
        $stack = new RequestStack();
        $stack->push($request);

        return new SecretRevealer(
            $this->registry(),
            $settings,
            new AccessGuard(new AllowAllInstanceSettingsAccessChecker(), true, null, $stack),
            $this->dispatcher,
            $this->logger,
            new MockClock('2026-11-13 09:00:00'),
            $rateLimiter,
            'test-salt',
            $stack,
        );
    }

    public function testWithoutALoggerAClockAndADispatcherTheRevealStillWorks(): void
    {
        $settings = $this->settings();
        $settings->save('oauth', ['client_secret' => self::SECRET]);
        $revealer = new SecretRevealer($this->registry(), $settings, new AccessGuard(new AllowAllInstanceSettingsAccessChecker(), true));

        self::assertSame(self::SECRET, $revealer->reveal('oauth', 'client_secret'));
    }

    private function revealer(InstanceSettings $settings, ?AccessGuard $guard = null, ?RateLimiterFactory $rateLimiter = null): SecretRevealer
    {
        return new SecretRevealer(
            $this->registry(),
            $settings,
            $guard ?? new AccessGuard(new AllowAllInstanceSettingsAccessChecker(), true),
            $this->dispatcher,
            $this->logger,
            new MockClock('2026-11-13 09:00:00'),
            $rateLimiter,
            'test-salt',
        );
    }

    /**
     * Guard of a user holding `$granted`, with the role-based checker (reveal roles: ROLE_DEVELOPER, as configured by
     * default).
     *
     * @param list<string> $granted
     * @param array<string, list<string>> $areaRoles
     */
    private function roleGuard(array $granted, array $areaRoles = []): AccessGuard
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
        $checker = new ConfigurableInstanceSettingsAccessChecker($authorization, ['ROLE_ADMIN'], $areaRoles + [InstanceSettingsAccessCheckerInterface::AREA_REVEAL => ['ROLE_DEVELOPER']]);
        $request = new Request();
        $request->attributes->set('_firewall_context', 'main');
        $stack = new RequestStack();
        $stack->push($request);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('user', null, $granted), 'main', $granted));

        return new AccessGuard($checker, false, $storage, $stack);
    }

    private function settings(): InstanceSettings
    {
        return new InstanceSettings($this->repository, $this->registry(), new ValueNormalizer(Validation::createValidator()));
    }

    private function registry(): SettingsSectionRegistry
    {
        return new SettingsSectionRegistry([new RevealFixtureSection()]);
    }
}
