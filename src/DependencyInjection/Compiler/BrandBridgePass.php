<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection\Compiler;

use Nowo\InstanceSettingsBundle\Brand\InstanceBrandProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function in_array;

/**
 * Brand bridge (1.5, spec 220 §2.2): with `nowo_instance_settings.brand.enabled: true`, UiKit's appearance enabled and
 * no host `nowo_ui_kit.appearance.provider` (UiKit's parameter `nowo_ui_kit.appearance.provider_is_default`), points
 * `nowo_ui_kit.appearance.provider` at {@see InstanceBrandProvider}, which also reads the stored corner style, border
 * strength and fixed footer.
 *
 * The decision keys on `provider_is_default` (audit IS-I6), so it is the same whichever of this pass and UiKit's
 * `BrandAppearanceProviderPass` runs first: the alias may still target UiKit's config provider (this pass first) or
 * UiKit's brand candidate (UiKit's pass first); both are taken over. Any other target is a host alias of the provider
 * id in its own services, and the host always wins (nothing changes, nothing is logged).
 */
final class BrandBridgePass implements CompilerPassInterface
{
    /** Container parameter set by the extension. */
    public const PARAMETER = 'nowo_instance_settings.brand.enabled';

    /** UiKit's provider alias. */
    public const PROVIDER_ALIAS = 'nowo_ui_kit.appearance.provider';

    /** Targets of the provider alias that are UiKit's own defaults (config provider, brand candidate). */
    public const UI_KIT_DEFAULTS = ['nowo_ui_kit.appearance.config_provider', 'nowo_ui_kit.appearance.brand_provider'];

    /**
     * Re-aliases UiKit's appearance provider to the bridge when the host set none.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$this->isTrue($container, self::PARAMETER)
            || !$this->isTrue($container, 'nowo_ui_kit.appearance.enabled')
            || !$this->isTrue($container, 'nowo_ui_kit.appearance.provider_is_default')
            || !$container->hasAlias(self::PROVIDER_ALIAS)
            || !$container->hasDefinition(InstanceBrandProvider::class)) {
            return;
        }
        $alias = $container->getAlias(self::PROVIDER_ALIAS);
        if (!in_array((string) $alias, self::UI_KIT_DEFAULTS, true)) {
            return;
        }
        $container->setAlias(self::PROVIDER_ALIAS, InstanceBrandProvider::class)->setPublic($alias->isPublic());
    }

    private function isTrue(ContainerBuilder $container, string $parameter): bool
    {
        return $container->hasParameter($parameter) && $container->getParameter($parameter) === true;
    }
}
