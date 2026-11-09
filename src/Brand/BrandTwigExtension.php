<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Brand;

use Nowo\Brand\Contract\Brand;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `nowo_brand()` (1.5, `brand.enabled: true`): the contract {@see Brand} for host templates (name, colours, assets,
 * version). A function backed by the bridge service, never a Twig global built at container load.
 */
final class BrandTwigExtension extends AbstractExtension
{
    /**
     * @param InstanceBrandProvider $brand the bridge
     */
    public function __construct(
        private readonly InstanceBrandProvider $brand,
    ) {
    }

    /**
     * Registers `nowo_brand()`.
     *
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [new TwigFunction('nowo_brand', $this->brand->getBrand(...))];
    }
}
