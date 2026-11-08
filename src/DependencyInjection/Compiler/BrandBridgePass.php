<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\InstanceSettingsBundle\Brand\InstanceBrandProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Brand bridge (1.5, spec 220 §2.2): with `nowo_instance_settings.brand.enabled: true`, UiKit's appearance enabled, no
 * host `nowo_ui_kit.appearance.provider` (UiKit's parameter `nowo_ui_kit.appearance.provider_is_default`) and UiKit's
 * brand mode `auto`, points `nowo_ui_kit.appearance.provider` at {@see InstanceBrandProvider}, which also reads the
 * stored corner style, border strength and fixed footer.
 *
 * The decision keys on `provider_is_default` (audit IS-I6), so it is the same whichever of this pass and UiKit's
 * `BrandAppearanceProviderPass` runs first: the alias may still target UiKit's config provider while UiKit's brand
 * candidate is registered (this pass first) or target that candidate (UiKit's pass first); both are taken over. UiKit
 * registers the candidate only with `appearance.brand: auto`, so `brand: off` keeps UiKit's config provider (audit
 * IU-L1). Any other target is a host alias of the provider id in its own services, and the host always wins.
 *
 * With brand.enabled, a host `BrandProviderInterface` service or alias other than the bridge fails the build (two
 * brands, audit IU-I2).
 */
final class BrandBridgePass implements CompilerPassInterface
{
    /** Container parameter set by the extension. */
    public const PARAMETER = 'nowo_instance_settings.brand.enabled';

    /** UiKit's provider alias. */
    public const PROVIDER_ALIAS = 'nowo_ui_kit.appearance.provider';

    /** UiKit's config provider (the alias target while nothing re-aliased it). */
    public const UI_KIT_CONFIG_PROVIDER = 'nowo_ui_kit.appearance.config_provider';

    /** UiKit's brand candidate (registered only for `provider: null`, `brand: auto` and brand-contracts installed). */
    public const UI_KIT_BRAND_CANDIDATE = 'nowo_ui_kit.appearance.brand_provider';

    /**
     * Re-aliases UiKit's appearance provider to the bridge when the host set none and kept UiKit's brand mode `auto`.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$this->isTrue($container, self::PARAMETER)) {
            return;
        }
        $this->assertSingleBrand($container);
        if (!$this->isTrue($container, 'nowo_ui_kit.appearance.enabled')
            || !$this->isTrue($container, 'nowo_ui_kit.appearance.provider_is_default')
            || !$container->hasAlias(self::PROVIDER_ALIAS)
            || !$container->hasDefinition(InstanceBrandProvider::class)) {
            return;
        }
        $alias  = $container->getAlias(self::PROVIDER_ALIAS);
        $target = (string) $alias;
        $auto   = $target === self::UI_KIT_BRAND_CANDIDATE
            || ($target === self::UI_KIT_CONFIG_PROVIDER && $container->hasDefinition(self::UI_KIT_BRAND_CANDIDATE));
        if (!$auto) {
            return;
        }
        $container->setAlias(self::PROVIDER_ALIAS, InstanceBrandProvider::class)->setPublic($alias->isPublic());
    }

    /**
     * Audit IU-I2: with brand.enabled the bridge is the brand; a host service or alias of the interface pointing
     * elsewhere would give Pwa, mail and legal one brand and UiKit another.
     */
    private function assertSingleBrand(ContainerBuilder $container): void
    {
        $id = BrandProviderInterface::class;
        if ($container->hasDefinition($id) || ($container->hasAlias($id) && (string) $container->getAlias($id) !== InstanceBrandProvider::class)) {
            throw new LogicException('NowoInstanceSettingsBundle: nowo_instance_settings.brand.enabled is true but the host defines its own Nowo\Brand\Contract\BrandProviderInterface service or alias. Use one brand source: remove the host alias (the bridge serves the stored brand) or set brand.enabled: false (the host provider serves it).');
        }
    }

    private function isTrue(ContainerBuilder $container, string $parameter): bool
    {
        return $container->hasParameter($parameter) && $container->getParameter($parameter) === true;
    }
}
