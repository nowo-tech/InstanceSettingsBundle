<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use Nowo\InstanceSettingsBundle\Seed\FallbackSeeder;

/**
 * Optional interface of a {@see SettingsSectionInterface}: exposes the bootstrap values the section falls back to
 * while nothing is stored (bundle configuration, usually `%env(...)%` placeholders), so that
 * {@see FallbackSeeder} / `nowo:instance-settings:seed-from-fallbacks` can copy them once into the store and the
 * instance can then run with its settings in the database only ("DB-only mode").
 *
 * Only fields without a stored value are seeded; empty fallbacks (null, '') are ignored.
 */
interface SectionFallbackProviderInterface
{
    /**
     * Fallback values of the section fields (one per field at most; unknown fields are reported and skipped).
     *
     * @return iterable<SettingFallback>
     */
    public function getFallbacks(): iterable;
}
