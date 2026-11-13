<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\EventListener;

use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\EventListener\InstanceSettingsAccessListener;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Secret\SecretRevealer;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

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
        // and only for the reveal route, with the names of the route and never a value.
        $logger   = new RecordingLogger();
        $listener = new InstanceSettingsAccessListener(new AccessGuard($this->checker(false)), '/admin/settings', $logger);

        // A denied section page is not a reveal: nothing is logged here.
        $this->deny($listener, $this->event('/admin/settings/sections/mailer'));
        self::assertSame([], $logger->records);

        // A denied reveal: one warning, with the section and field of the matched route.
        $reveal = $this->event('/admin/settings/sections/oauth/secrets/client_secret/reveal');
        $reveal->getRequest()->attributes->set('_route', InstanceSettingsRoutes::REVEAL);
        $reveal->getRequest()->attributes->set('_route_params', ['section' => 'oauth', 'field' => 'client_secret']);
        $this->deny($listener, $reveal);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(SecretRevealer::LOG_REFUSED, $logger->records[0]['message']);
        self::assertSame('oauth.client_secret', $logger->records[0]['context']['key']);
        self::assertSame(InstanceSettingsAccessListener::REASON_UI_GATE, $logger->records[0]['context']['reason']);
        self::assertNull($logger->records[0]['context']['actor'], 'the gate cannot name an actor');

        // A reveal route without usable parameters still logs, naming what it has.
        $bare = $this->event('/admin/settings/sections/oauth/secrets/client_secret/reveal');
        $bare->getRequest()->attributes->set('_route', InstanceSettingsRoutes::REVEAL);
        $bare->getRequest()->attributes->set('_route_params', 'not-an-array');
        $this->deny($listener, $bare);
        self::assertSame('?.?', $logger->records[1]['context']['key']);
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
