<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The link of an expiry reminder (1.21, KIT-88): `expiring_secrets.notify.link_route` (default: the section's settings
 * page) with the `section` parameter, or `fallback_link_route` (no parameter) for a recipient who cannot open the
 * settings. A route left null gives no link. Built per call from the router (its request context in a console run
 * comes from `framework.router.default_uri`).
 */
final readonly class ExpiryNoticeLinks
{
    /**
     * @param string|null $route route of the recipients who can open the settings (null = no link)
     * @param string|null $fallbackRoute route of the others (null = no link)
     */
    public function __construct(
        private UrlGeneratorInterface $urls,
        private ?string $route,
        private ?string $fallbackRoute = null,
    ) {
    }

    /**
     * The link for `$recipient`, absolute (mail) or a path (bell); null when there is none.
     */
    public function link(SecretExpiryThresholdReachedEvent $event, ExpiryRecipient $recipient, bool $absolute): ?string
    {
        $route = $recipient->canOpenSettings ? $this->route : $this->fallbackRoute;
        if ($route === null) {
            return null;
        }

        return $this->urls->generate(
            $route,
            $recipient->canOpenSettings ? ['section' => $event->section] : [],
            $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }
}
