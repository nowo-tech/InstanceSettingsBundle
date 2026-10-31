<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed when the admin UI is enabled without form CSRF protection (REQ-SEC-005, pattern P2): every mutation of
 * the UI is a Symfony Form whose token would not be checked without `form.type_extension.csrf`, the token manager or
 * with `framework.form.csrf_protection: false`.
 */
final class CsrfProtectionPass implements CompilerPassInterface
{
    /**
     * Throws when the controller is registered and form CSRF is unavailable.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SettingsController::class)) {
            return;
        }

        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;

        if (!$container->has('security.csrf.token_manager') || !$container->hasDefinition('form.type_extension.csrf') || $formCsrfDisabled) {
            throw new LogicException('NowoInstanceSettingsBundle: the admin UI (web_ui.enabled: true) requires CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection (composer require symfony/security-csrf), or set nowo_instance_settings.web_ui.enabled: false.');
        }
    }
}
