<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\EventListener;

use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\EventListener\InstanceSettingsAccessListener;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Secret\RevealRefusalLog;
use Nowo\InstanceSettingsBundle\Secret\SecretRevealer;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\RevealFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;
use Nowo\InstanceSettingsBundle\Tests\Unit\Secret\RevealRefusalLogTest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_column;

final class InstanceSettingsAccessListenerTest extends TestCase
{
    public function testListensToKernelRequestAfterTheFirewall(): void
    {
        $attribute = (new ReflectionMethod(InstanceSettingsAccessListener::class, 'onKernelRequest'))->getAttributes(AsEventListener::class)[0]->newInstance();
        self::assertSame(KernelEvents::REQUEST, $attribute->event);
        self::assertSame(7, $attribute->priority);
    }

    public function testGuardsThePrefixAndBundleControllers(): void
    {
        $subscriber = new InstanceSettingsAccessListener(new AccessGuard($this->checker(false)), '/admin/settings/');

        // Outside the prefix: untouched.
        $subscriber->onKernelRequest($this->event('/admin/other'));
        $subscriber->onKernelRequest($this->event('/admin/settingsx'));
        $subscriber->onKernelRequest($this->event('/x', HttpKernelInterface::SUB_REQUEST));
        $this->addToAssertionCount(3);

        foreach ([
            $this->event('/admin/settings'),
            $this->event('/admin/settings/sections/mailer'),
            $this->event('/elsewhere', HttpKernelInterface::MAIN_REQUEST, SettingsController::class . '::index'),
            $this->event('/fragment', HttpKernelInterface::SUB_REQUEST, [SettingsController::class, 'index']),
        ] as $event) {
            try {
                $subscriber->onKernelRequest($event);
                self::fail('Expected AccessDeniedHttpException');
            } catch (AccessDeniedHttpException $e) {
                self::assertSame('Access denied to the instance settings UI.', $e->getMessage());
            }
        }
    }

    public function testAllowsWhenGranted(): void
    {
        $subscriber = new InstanceSettingsAccessListener(new AccessGuard($this->checker(true), true), '/admin/settings');
        $subscriber->onKernelRequest($this->event('/admin/settings/portability'));
        $this->addToAssertionCount(1);
    }

    public function testOnlyADeniedRevealIsLoggedAsARevealRefusal(): void
    {
        // 1.15 (audit F6): the gate denies before the endpoint runs, so it writes the reveal refusal line itself —
        // and only for the reveal route, with the declared names of the route and never a value.
        $logger   = new RecordingLogger();
        $listener = $this->listener(new AccessGuard($this->checker(false)), $logger, $this->limiter(10));

        // A denied section page is not a reveal: nothing is logged here.
        $this->deny($listener, $this->event('/admin/settings/sections/mailer'));
        self::assertSame([], $logger->records);

        // A denied reveal: one warning, with the section and field of the matched route.
        $this->deny($listener, $this->reveal('oauth', 'client_secret'));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(SecretRevealer::LOG_REFUSED, $logger->records[0]['message']);
        self::assertSame('oauth.client_secret', $logger->records[0]['context']['key']);
        self::assertSame(InstanceSettingsAccessListener::REASON_UI_GATE, $logger->records[0]['context']['reason']);
        self::assertNull($logger->records[0]['context']['actor'], 'nobody is authenticated here');

        // A reveal route without usable parameters still logs, as the unknown key.
        $bare = $this->event('/admin/settings/sections/oauth/secrets/client_secret/reveal');
        $bare->getRequest()->attributes->set('_route', InstanceSettingsRoutes::REVEAL);
        $bare->getRequest()->attributes->set('_route_params', 'not-an-array');
        $this->deny($listener, $bare);
        self::assertSame(RevealRefusalLog::KEY_UNKNOWN, $logger->records[1]['context']['key']);
    }

    public function testTheGateRefusalNamesTheResolvedActor(): void
    {
        // 1.15.1 (audit N2): the line used to hard-code `actor => null`, so the per-actor alert could not group the
        // refusals of an account that cannot open the UI. It now carries the user identifier, as every other reveal
        // line of the bundle does.
        $logger   = new RecordingLogger();
        $listener = $this->listener($this->userGuard('viewer'), $logger, $this->limiter(10));

        $this->deny($listener, $this->reveal('oauth', 'client_secret'));
        self::assertSame('viewer', $logger->records[0]['context']['actor']);
    }

    public function testNamesTheRegistryDoesNotDeclareAreNeverEchoed(): void
    {
        // 1.15.1 (audit N3): the route accepts any lower-case name, so an unauthenticated caller chose what the
        // warning said. Only declared names reach the line now; anything else is the constant `unknown`.
        $logger   = new RecordingLogger();
        $listener = $this->listener(new AccessGuard($this->checker(false)), $logger, $this->limiter(10));

        $this->deny($listener, $this->reveal('oauth', 'attacker_chosen'));
        $this->deny($listener, $this->reveal('attacker_chosen', 'client_secret'));
        self::assertSame(RevealRefusalLog::KEY_UNKNOWN, $logger->records[0]['context']['key']);
        self::assertSame(RevealRefusalLog::KEY_UNKNOWN, $logger->records[1]['context']['key']);
        self::assertStringNotContainsString('attacker_chosen', $logger->dump());
    }

    public function testGateRefusalLinesAreThrottledAndEveryCallIsStillRefused(): void
    {
        // 1.15.1 (audit N3): past the limiter's budget the line drops to debug; the refusal itself never relaxes.
        $logger   = new RecordingLogger();
        $listener = $this->listener(new AccessGuard($this->checker(false)), $logger, $this->limiter(2));

        for ($i = 0; $i < 4; ++$i) {
            $this->deny($listener, $this->reveal('oauth', 'client_secret'));
        }
        self::assertSame(['warning', 'warning', 'debug', 'debug'], array_column($logger->records, 'level'));
    }

    public function testAFailingRefusalLogNeverTurnsTheRefusalIntoAnAnswer(): void
    {
        // Fail closed: a registry that throws (a revealable field without `reveal_rate_limiter`) must not replace the
        // 403 with anything else.
        $refusals = new RevealRefusalLog(new SettingsSectionRegistry([new RevealFixtureSection()], revealNeedsRateLimiter: true), new AccessGuard($this->checker(false)), new RecordingLogger());
        $listener = new InstanceSettingsAccessListener(new AccessGuard($this->checker(false)), '/admin/settings', null, $refusals);

        $this->deny($listener, $this->reveal('oauth', 'client_secret'));
    }

    public function testABrokenLimiterOrLoggerStillLeavesTheGates403(): void
    {
        // Audit PJ-L1: the refusal log never throws, and the deny sits in a finally anyway.
        $guard  = new AccessGuard($this->checker(false));
        $broken = new RateLimiterFactory(['id' => 'reveal', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '1 hour'], RevealRefusalLogTest::brokenStorage());
        $this->deny(new InstanceSettingsAccessListener($guard, '/admin/settings', null, new RevealRefusalLog(new SettingsSectionRegistry([new RevealFixtureSection()]), $guard, new RecordingLogger(), null, $broken)), $this->reveal('oauth', 'client_secret'));
        $this->deny(new InstanceSettingsAccessListener($guard, '/admin/settings', RevealRefusalLogTest::throwingLogger()), $this->reveal('oauth', 'client_secret'));
    }

    public function testAHandBuiltListenerWithOnlyALoggerNeverEchoesNames(): void
    {
        // Built by hand with the 1.15 signature (no refusal log): no registry to check names against, so the key is
        // always `unknown`; no limiter either, so an unnamed caller only writes debug lines.
        $logger   = new RecordingLogger();
        $listener = new InstanceSettingsAccessListener(new AccessGuard($this->checker(false)), '/admin/settings', $logger);

        $this->deny($listener, $this->reveal('oauth', 'client_secret'));
        self::assertSame(RevealRefusalLog::KEY_UNKNOWN, $logger->records[0]['context']['key']);
        self::assertSame('debug', $logger->records[0]['level']);
    }

    public function testWithoutLoggerOrRefusalLogADeniedRevealIsStillRefused(): void
    {
        $this->deny(new InstanceSettingsAccessListener(new AccessGuard($this->checker(false)), '/admin/settings'), $this->reveal('oauth', 'client_secret'));
    }

    private function listener(AccessGuard $guard, RecordingLogger $logger, RateLimiterFactory $limiter): InstanceSettingsAccessListener
    {
        $refusals = new RevealRefusalLog(new SettingsSectionRegistry([new RevealFixtureSection()]), $guard, $logger, null, $limiter, 'test-salt');

        return new InstanceSettingsAccessListener($guard, '/admin/settings', $logger, $refusals);
    }

    private function reveal(string $section, string $field): RequestEvent
    {
        $event = $this->event('/admin/settings/sections/' . $section . '/secrets/' . $field . '/reveal');
        $event->getRequest()->attributes->set('_route', InstanceSettingsRoutes::REVEAL);
        $event->getRequest()->attributes->set('_route_params', ['section' => $section, 'field' => $field]);

        return $event;
    }

    private function limiter(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => 'reveal', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour'], new InMemoryStorage());
    }

    /**
     * A guard denying `$identifier`, an authenticated user of the main request firewall.
     */
    private function userGuard(string $identifier): AccessGuard
    {
        $request = Request::create('/admin/settings');
        $request->attributes->set('_firewall_context', 'main');
        $stack = new RequestStack();
        $stack->push($request);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser($identifier, null, ['ROLE_USER']), 'main', ['ROLE_USER']));

        return new AccessGuard($this->checker(false), false, $storage, $stack);
    }

    private function deny(InstanceSettingsAccessListener $listener, RequestEvent $event): void
    {
        try {
            $listener->onKernelRequest($event);
            self::fail('Expected AccessDeniedHttpException');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param array{0: string, 1: string}|string|null $controller
     */
    private function event(string $path, int $type = HttpKernelInterface::MAIN_REQUEST, string|array|null $controller = null): RequestEvent
    {
        $request = Request::create($path);
        if ($controller !== null) {
            $request->attributes->set('_controller', $controller);
        }

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }

    private function checker(bool $result): InstanceSettingsAccessCheckerInterface
    {
        return new class($result) implements InstanceSettingsAccessCheckerInterface {
            public function __construct(private readonly bool $result)
            {
            }

            public function canAccess(?object $user, ?string $area = null): bool
            {
                return $this->result;
            }
        };
    }
}
