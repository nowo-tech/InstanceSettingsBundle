<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Secret;

use Nowo\InstanceSettingsBundle\Secret\RevealRefusalLog;
use Nowo\InstanceSettingsBundle\Secret\SecretRevealer;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\AllowAllInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\RevealFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_column;
use function hash;

/**
 * 1.15.1 (audit N2 / N3): the reveal refusal line names the resolved actor, never echoes a name the registry does not
 * declare, and is throttled per caller so an unauthenticated client cannot flood the per-actor alert.
 */
final class RevealRefusalLogTest extends TestCase
{
    private RecordingLogger $logger;

    private ?RequestStack $stack = null;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testTheLineNamesTheResolvedActorAndTheDeclaredKey(): void
    {
        $this->log($this->guard('developer'), $this->limiter(5))->log('oauth', 'client_secret', SecretRevealer::REASON_FORBIDDEN);

        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame(LogLevel::WARNING, $record['level']);
        self::assertSame(SecretRevealer::LOG_REFUSED, $record['message']);
        self::assertSame([
            'key'    => 'oauth.client_secret',
            'actor'  => 'developer',
            'at'     => '2026-11-14T09:00:00+00:00',
            'reason' => SecretRevealer::REASON_FORBIDDEN,
        ], $record['context']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function keys(): iterable
    {
        yield 'a revealable secret' => ['oauth', 'client_secret', 'oauth.client_secret'];
        yield 'a declared secret without the opt-in' => ['oauth', 'signing_key', 'oauth.signing_key'];
        yield 'a declared plain field' => ['oauth', 'client_id', 'oauth.client_id'];
        yield 'an unknown field' => ['oauth', 'attacker_chosen', RevealRefusalLog::KEY_UNKNOWN];
        yield 'a look-alike field' => ['oauth', 'client_secret_', RevealRefusalLog::KEY_UNKNOWN];
        yield 'another case' => ['oauth', 'Client_Secret', RevealRefusalLog::KEY_UNKNOWN];
        yield 'a field with a newline' => ['oauth', "client_secret\nforged", RevealRefusalLog::KEY_UNKNOWN];
        yield 'an unknown section' => ['attacker_chosen', 'client_secret', RevealRefusalLog::KEY_UNKNOWN];
        yield 'a padded section' => ['oauth ', 'client_secret', RevealRefusalLog::KEY_UNKNOWN];
        yield 'empty names' => ['', '', RevealRefusalLog::KEY_UNKNOWN];
    }

    #[DataProvider('keys')]
    public function testOnlyDeclaredNamesAreLoggedEverythingElseIsAConstant(string $section, string $field, string $expected): void
    {
        $this->log($this->guard('developer'), $this->limiter(5))->log($section, $field, SecretRevealer::REASON_NOT_REVEALABLE);

        self::assertSame($expected, $this->logger->records[0]['context']['key']);
        if ($expected === RevealRefusalLog::KEY_UNKNOWN) {
            self::assertStringNotContainsString('attacker_chosen', $this->logger->dump());
            self::assertStringNotContainsString('forged', $this->logger->dump());
        }
    }

    public function testRefusalLinesAreThrottledPerCallerAndDropToDebug(): void
    {
        // N3: the limiter caps the warning lines of one caller; past it the line is still written, at debug, so the
        // alert counting warnings per actor cannot be flooded.
        $limiter = $this->limiter(2);
        $log     = $this->log($this->guard(null, '203.0.113.9'), $limiter);
        for ($i = 0; $i < 5; ++$i) {
            $log->log('oauth', 'client_secret', SecretRevealer::REASON_FORBIDDEN);
        }
        self::assertSame([LogLevel::WARNING, LogLevel::WARNING, LogLevel::DEBUG, LogLevel::DEBUG, LogLevel::DEBUG], array_column($this->logger->records, 'level'));

        // Another caller has its own budget: one client cannot silence the lines of everybody else.
        $this->log($this->guard(null, '198.51.100.7'), $limiter)->log('oauth', 'client_secret', SecretRevealer::REASON_FORBIDDEN);
        $this->log($this->guard('developer'), $limiter)->log('oauth', 'client_secret', SecretRevealer::REASON_FORBIDDEN);
        self::assertSame(LogLevel::WARNING, $this->logger->records[5]['level']);
        self::assertSame(LogLevel::WARNING, $this->logger->records[6]['level']);
    }

    public function testTheLogBudgetIsNotTheRevealBudget(): void
    {
        // The refusal lines consume a bucket of their own: a burst of refusals never eats the reveals of that caller,
        // and the reveal key keeps its 1.15 shape (existing buckets stay valid across the upgrade).
        $storage = new InMemoryStorage();
        $limiter = new RateLimiterFactory(['id' => 'reveal', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], $storage);
        $log     = $this->log($this->guard('developer'), $limiter);
        $log->log('oauth', 'client_secret', SecretRevealer::REASON_FORBIDDEN);
        $log->log('oauth', 'client_secret', SecretRevealer::REASON_FORBIDDEN);

        self::assertNotSame($log->rateLimitKey(), $log->rateLimitKey(RevealRefusalLog::BUCKET_REFUSAL_LOG));
        self::assertSame(hash('sha256', "test-salt\0developer"), $log->rateLimitKey());
        self::assertTrue($limiter->create($log->rateLimitKey())->consume()->isAccepted(), 'the reveal budget is untouched');
    }

    public function testWithoutALimiterAnUnnamedCallerOnlyWritesDebugLines(): void
    {
        // No `reveal_rate_limiter`: nothing in the bundle is revealable (the registry fails closed), so there is no
        // brake on these lines. A caller the host cannot name gets debug lines; a named one keeps its warning (it is
        // accountable and groups under its own name).
        $this->log($this->guard(null, '203.0.113.9'), null)->log('oauth', 'client_secret', 'ui_gate');
        $this->log($this->guard('developer'), null)->log('oauth', 'client_secret', 'ui_gate');

        self::assertSame([LogLevel::DEBUG, LogLevel::WARNING], array_column($this->logger->records, 'level'));
        self::assertNull($this->logger->records[0]['context']['actor']);
    }

    public function testWithoutALoggerNothingIsConsumed(): void
    {
        $storage = new InMemoryStorage();
        $limiter = new RateLimiterFactory(['id' => 'reveal', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], $storage);
        $log     = new RevealRefusalLog($this->registry(), $this->guard('developer'), null, null, $limiter, 'test-salt');
        $log->log('oauth', 'client_secret', SecretRevealer::REASON_FORBIDDEN);

        self::assertTrue($limiter->create($log->rateLimitKey(RevealRefusalLog::BUCKET_REFUSAL_LOG))->consume()->isAccepted());
    }

    public function testWithoutAClockTheSystemTimeIsUsed(): void
    {
        (new RevealRefusalLog($this->registry(), $this->guard('developer'), $this->logger))->log('oauth', 'client_secret', SecretRevealer::REASON_FORBIDDEN);

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $this->logger->records[0]['context']['at']);
    }

    public function testAnUnnamedCallerWithoutARequestSharesTheAnonymousKey(): void
    {
        $log = new RevealRefusalLog($this->registry(), new AccessGuard(new AllowAllInstanceSettingsAccessChecker(), true), $this->logger, null, null, 'test-salt');

        self::assertNull($log->actor());
        self::assertSame(hash('sha256', "test-salt\0anonymous"), $log->rateLimitKey());
    }

    private function log(AccessGuard $guard, ?RateLimiterFactory $limiter): RevealRefusalLog
    {
        return new RevealRefusalLog($this->registry(), $guard, $this->logger, new MockClock('2026-11-14 09:00:00'), $limiter, 'test-salt', $this->stack);
    }

    /**
     * Guard of `$user` (null: a caller the host cannot name) on a request from `$ip`.
     */
    private function guard(?string $user, string $ip = '192.0.2.1'): AccessGuard
    {
        $request = Request::create('/admin/settings', server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->set('_firewall_context', 'main');
        $this->stack = new RequestStack();
        $this->stack->push($request);
        $storage = new TokenStorage();
        if ($user !== null) {
            $storage->setToken(new UsernamePasswordToken(new InMemoryUser($user, null, ['ROLE_USER']), 'main', ['ROLE_USER']));
        }

        return new AccessGuard(new AllowAllInstanceSettingsAccessChecker(), false, $storage, $this->stack);
    }

    private function limiter(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => 'reveal', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour'], new InMemoryStorage());
    }

    private function registry(): SettingsSectionRegistry
    {
        return new SettingsSectionRegistry([new RevealFixtureSection()]);
    }
}
