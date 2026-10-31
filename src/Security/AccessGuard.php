<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function is_object;

/**
 * Resolves the current user safely and asks the access checker (shared by the request subscriber and the controller).
 *
 * The token is trusted only when a firewall matched the main request (`_firewall_context`), so a token left in the
 * token storage by a previous worker request is never used. Stateless: safe in worker mode.
 */
final readonly class AccessGuard
{
    /**
     * @param bool $allowUnauthenticated `security.allow_unauthenticated` (dev / demo only; the container refuses it without kernel.debug)
     */
    public function __construct(
        private InstanceSettingsAccessCheckerInterface $accessChecker,
        private bool $allowUnauthenticated = false,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * Whether the current user may open the UI (`$area` null), one section or the import / export page.
     */
    public function isGranted(?string $area = null): bool
    {
        if ($this->allowUnauthenticated) {
            return true;
        }

        return $this->accessChecker->canAccess($this->user(), $area);
    }

    /**
     * Authenticated user of the main request firewall, or null.
     */
    public function user(): ?object
    {
        $mainRequest = $this->requestStack?->getMainRequest();
        if (!$mainRequest instanceof Request || !$mainRequest->attributes->has('_firewall_context')) {
            return null;
        }

        $user = $this->tokenStorage?->getToken()?->getUser();

        return is_object($user) ? $user : null;
    }

    /**
     * Denies the current request: inside a firewall a Security {@see AccessDeniedException} (the firewall turns it into
     * its entry point for anonymous users — 401 / login redirect — and 403 otherwise), else a plain 403.
     */
    public function deny(string $message): never
    {
        $mainRequest = $this->requestStack?->getMainRequest();
        if ($mainRequest instanceof Request && $mainRequest->attributes->has('_firewall_context')) {
            throw new AccessDeniedException($message);
        }

        throw new AccessDeniedHttpException($message);
    }
}
