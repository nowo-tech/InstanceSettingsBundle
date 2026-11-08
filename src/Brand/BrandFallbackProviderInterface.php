<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Brand;

/**
 * Host seam of the brand bridge (spec 220 §7.1, `nowo_instance_settings.brand.fallback_provider`): the values the
 * `brand` and `appearance` sections seed from (`nowo:instance-settings:seed-from-fallbacks`), typically one read of
 * the host's legacy appearance row. Return only values that differ from the code defaults, so untouched fields keep
 * following future default changes. Image fields cannot be seeded (upload or import them); their keys are skipped.
 */
interface BrandFallbackProviderInterface
{
    /**
     * Fallback values of one section (`brand` or `appearance`): field name => value (null or '' = none).
     *
     * @return iterable<string, bool|float|int|string|null>
     */
    public function getFallbacks(string $section): iterable;
}
