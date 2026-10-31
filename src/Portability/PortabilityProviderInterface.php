<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Portability;

/**
 * Extra data a host or bundle adds to the instance configuration export (appearance, feature flags stored elsewhere,
 * domain presets …). Services implementing it are tagged `nowo_instance_settings.portability_provider`
 * (autoconfiguration) and appear under `providers.<name>` of the export envelope.
 *
 * Providers must never export secrets: credential-shaped keys (`*_password`, `*_secret`, `*_token`, `*_key`, `*_dsn`,
 * `password`, `secret`, `dsn`, …) holding strings are refused on import before {@see self::validate()} runs.
 */
interface PortabilityProviderInterface
{
    /**
     * Stable key under `providers` (lower snake case, unique, different from every settings section name).
     */
    public function getName(): string;

    /**
     * JSON-safe data of this provider (no secrets).
     *
     * @return array<string, mixed>
     */
    public function export(): array;

    /**
     * Validates imported data without side effects.
     *
     * @param array<string, mixed> $data
     *
     * @throws PortabilityException with a reason code (e.g. `invalid_value:appearance.color`)
     */
    public function validate(array $data): void;

    /**
     * Applies imported data (already validated). Runs inside the import transaction.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string> changed keys (for the import report)
     */
    public function import(array $data): array;
}
