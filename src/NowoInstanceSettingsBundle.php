<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle;

use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\SecretEncryptionPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\SettingsSectionPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\UnauthenticatedAccessPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Runtime instance settings for Symfony apps: one settings store split into tagged sections, encrypted secret fields,
 * admin forms and import / export (portability).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoInstanceSettingsBundle extends Bundle
{
    /** Translation domain of the bundle UI copy (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = 'NowoInstanceSettingsBundle';

    /** FormKit profile (`nowo_form_kit.profiles.instance_settings`) of every bundle form type (REQ-TWIG-005 rule 11). */
    public const FORM_KIT_PROFILE = 'instance_settings';

    /** Service tag of the settings sections ({@see Section\SettingsSectionInterface}). */
    public const SECTION_TAG = 'nowo_instance_settings.section';

    /** Service tag of the portability providers ({@see Portability\PortabilityProviderInterface}). */
    public const PORTABILITY_PROVIDER_TAG = 'nowo_instance_settings.portability_provider';

    /**
     * Registers the compiler passes: Twig paths, CSRF and encryption fail-closed guards, demo-only unauthenticated access, section tags.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new CsrfProtectionPass());
        $container->addCompilerPass(new UnauthenticatedAccessPass());
        $container->addCompilerPass(new SecretEncryptionPass());
        // Before the tagged iterators are resolved (PassConfig::TYPE_BEFORE_OPTIMIZATION, priority 10).
        $container->addCompilerPass(new SettingsSectionPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }

    /**
     * The extension alias is `nowo_instance_settings` (not the default derived from the class name).
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoInstanceSettingsExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
