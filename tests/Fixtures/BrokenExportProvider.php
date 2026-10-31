<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Portability\PortabilityProviderInterface;

use const NAN;

/**
 * Exports a value JSON cannot encode (export failure path).
 */
final class BrokenExportProvider implements PortabilityProviderInterface
{
    public function getName(): string
    {
        return 'broken';
    }

    public function export(): array
    {
        return ['ratio' => NAN];
    }

    public function validate(array $data): void
    {
    }

    public function import(array $data): array
    {
        return [];
    }
}
