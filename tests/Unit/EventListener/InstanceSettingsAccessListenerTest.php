<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\EventListener;

use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\EventListener\InstanceSettingsAccessListener;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
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
