<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\EventListener;

use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Secret\RevealRefusalLog;
use Nowo\InstanceSettingsBundle\Secret\SecretRevealer;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
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
 *
 * 1.15 (audit F6): this gate is in front of the secret reveal endpoint, so an account that cannot open the UI at all is
 * refused here, before {@see SecretRevealer} ever runs. Such a call would otherwise leave no reveal-specific line in
 * the log, and an alert counting reveal refusals per actor would miss exactly the probe that knows the least. The
 * listener therefore writes the same refusal line itself, with the reason `ui_gate`, when the request it denies is the
 * reveal route.
 *
 * 1.15.1 (audit N2 / N3): that line is written by {@see RevealRefusalLog}, like {@see SecretRevealer}'s own: it names the
 * resolved actor (the user identifier, as every other reveal line of the bundle), never echoes a section or field name
 * the registry does not declare, and is throttled per caller by the reveal rate limiter. A failure while logging never
 * turns the refusal into anything else: the request is denied whatever happens.
 */
final readonly class InstanceSettingsAccessListener
{
    /** Listener priority (after the Symfony firewall at 8). */
    public const PRIORITY = 7;
    /** Namespace of the bundle controllers. */
    public const CONTROLLER_NAMESPACE = 'Nowo\\InstanceSettingsBundle\\Controller\\';

    /** Refusal reason of a reveal denied by this gate (1.15, audit F6). */
    public const REASON_UI_GATE = 'ui_gate';

    /**
     * @param string $pathPrefix `web_ui.path_prefix`
     * @param LoggerInterface|null $logger receives the refusal line of a denied reveal when no `$refusalLog` is given
     *                                     (a listener built by hand: the key is then always `unknown`)
     * @param RevealRefusalLog|null $refusalLog writes the refusal line of a denied reveal (1.15.1; wired by the extension)
     */
    public function __construct(
        private AccessGuard $guard,
        private string $pathPrefix,
        private ?LoggerInterface $logger = null,
        private ?RevealRefusalLog $refusalLog = null,
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
            try {
                $this->logRevealRefusal($request);
            } finally {
                // Fail closed: whatever the logging does, the request is refused.
                $this->guard->deny('Access denied to the instance settings UI.');
            }
        }
    }

    /**
     * Logs the reveal refusal of a request denied by the UI gate (1.15, audit F6): the same message and shape as
     * {@see SecretRevealer}'s own refusals, so one alerting rule covers both, never a value.
     */
    private function logRevealRefusal(Request $request): void
    {
        if ($request->attributes->get('_route') !== InstanceSettingsRoutes::REVEAL) {
            return;
        }
        // A listener built by hand with the 1.15 signature: no registry to check the names against (every key is
        // `unknown`) and no limiter (a caller the host cannot name only writes debug lines).
        $refusalLog = $this->refusalLog ?? new RevealRefusalLog(new SettingsSectionRegistry(), $this->guard, $this->logger);
        $parameters = $request->attributes->get('_route_params');
        $parameters = is_array($parameters) ? $parameters : [];
        $section    = $parameters['section'] ?? null;
        $field      = $parameters['field'] ?? null;

        $refusalLog->log(is_string($section) ? $section : '', is_string($field) ? $field : '', self::REASON_UI_GATE);
    }

    private function isBundleController(mixed $controller): bool
    {
        if (is_array($controller)) {
            $controller = $controller[0] ?? null;
        }

        return is_string($controller) && str_starts_with(ltrim($controller, '\\'), self::CONTROLLER_NAMESPACE);
    }
}
