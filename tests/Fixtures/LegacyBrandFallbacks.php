<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Brand\BrandFallbackProviderInterface;

/**
 * A host fallback provider (a legacy appearance row): values that differ from the defaults, plus one dark accent equal
 * to the `obsidian` preset (dropped by the section).
 */
final class LegacyBrandFallbacks implements BrandFallbackProviderInterface
{
    public function getFallbacks(string $section): iterable
    {
        return $section === 'brand' ? ['name' => 'Legacy'] : ['light_accent' => '#123456', 'dark_accent' => '#34D399'];
    }
}
