<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\EventListener;

use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_array;
use function is_string;
use function ltrim;
use function rtrim;
use function str_starts_with;

/**
 * Bundle-level gate of the admin UI (REQ-UI-002 layer 2; the host must also firewall `web_ui.path_prefix`).
 *
 * Runs after the firewall (priority 7 < 8); registered with an explicit `kernel.event_listener` tag (the service is
 * not autoconfigured, the attribute documents the event). Guards every main request under the prefix and every bundle controller
 * wherever it is mounted (another routing prefix, a `render(controller())` sub-request).
 */
final readonly class InstanceSettingsAccessListener
{
    /** Listener priority (after the Symfony firewall at 8). */
    public const PRIORITY = 7;
    /** Namespace of the bundle controllers. */
    public const CONTROLLER_NAMESPACE = 'Nowo\\InstanceSettingsBundle\\Controller\\';

    /**
     * @param string $pathPrefix `web_ui.path_prefix`
     */
    public function __construct(
        private AccessGuard $guard,
        private string $pathPrefix,
    ) {
    }

    /**
     * Throws 403 when the guard denies the current user.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request            = $event->getRequest();
        $isBundleController = $this->isBundleController($request->attributes->get('_controller'));
        if (!$event->isMainRequest()) {
            if (!$isBundleController) {
                return;
            }
        } else {
            $prefix = rtrim($this->pathPrefix, '/');
            $path   = $request->getPathInfo();
            if (!$isBundleController && $path !== $prefix && !str_starts_with($path, $prefix . '/')) {
                return;
            }
        }

        if (!$this->guard->isGranted()) {
            $this->guard->deny('Access denied to the instance settings UI.');
        }
    }

    private function isBundleController(mixed $controller): bool
    {
        if (is_array($controller)) {
            $controller = $controller[0] ?? null;
        }

        return is_string($controller) && str_starts_with(ltrim($controller, '\\'), self::CONTROLLER_NAMESPACE);
    }
}
