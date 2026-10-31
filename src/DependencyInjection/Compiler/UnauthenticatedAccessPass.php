<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * `security.allow_unauthenticated: true` is a dev / demo switch: the container refuses to compile unless `kernel.debug`
 * is true (also with the admin UI off, since kits authorise their own actions with the same access checker), so a demo configuration copied to production cannot open the settings (and
 * their secrets) without login.
 */
final class UnauthenticatedAccessPass implements CompilerPassInterface
{
    /** Container parameter holding `security.allow_unauthenticated`. */
    public const PARAMETER = 'nowo_instance_settings.security.allow_unauthenticated';

    /**
     * Throws when the switch is on outside debug.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER) || $container->getParameter(self::PARAMETER) !== true) {
            return;
        }

        if ($container->hasParameter('kernel.debug') && $container->getParameter('kernel.debug') === true) {
            return;
        }

        throw new InvalidConfigurationException('nowo_instance_settings.security.allow_unauthenticated: true is only allowed when kernel.debug is true (dev / demo): the admin UI would expose the instance settings without login. Set it to false and protect the UI with security.access_roles.');
    }
}
