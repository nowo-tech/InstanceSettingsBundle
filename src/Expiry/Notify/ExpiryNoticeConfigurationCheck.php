<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use LogicException;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

use function sprintf;

/**
 * Build-time check of the default expiry notifier (1.21): `notify.link_route`, `notify.fallback_link_route` and the
 * mail template pair must exist. Routes are known only once the router loaded them, so this runs as a **required**
 * cache warmer (`cache:clear`, `cache:warmup`, every deploy): a typo fails the build instead of the first reminder,
 * which would then be retried every day.
 */
final readonly class ExpiryNoticeConfigurationCheck implements CacheWarmerInterface
{
    /**
     * @param list<string> $routes routes the links use
     * @param string|null $template mail template without the suffix (null = no mail channel)
     */
    public function __construct(
        private RouterInterface $router,
        private ?Environment $twig,
        private array $routes,
        private ?string $template,
    ) {
    }

    /**
     * Required: a broken configuration must fail the build.
     */
    public function isOptional(): bool
    {
        return false;
    }

    /**
     * Throws a LogicException naming the missing route or template.
     *
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $collection = $this->router->getRouteCollection();
        foreach ($this->routes as $route) {
            if (!$collection->get($route) instanceof Route) {
                throw new LogicException(sprintf('NowoInstanceSettingsBundle: the expiry reminder route "%s" (expiring_secrets.notify.link_route / fallback_link_route, or the bundle routes for the default link) does not exist.', $route));
            }
        }
        if ($this->template !== null && $this->twig instanceof Environment) {
            foreach (['.html.twig', '.txt.twig'] as $suffix) {
                if (!$this->twig->getLoader()->exists($this->template . $suffix)) {
                    throw new LogicException(sprintf('NowoInstanceSettingsBundle: the expiry reminder template "%s%s" (expiring_secrets.notify.mail_template) does not exist.', $this->template, $suffix));
                }
            }
        }

        return [];
    }
}
