<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Portability\PortabilityProviderInterface;
use RuntimeException;

/**
 * A portability provider whose import fails after the sections were saved (the import transaction rolls back).
 */
final class FailingImportProvider implements PortabilityProviderInterface
{
    public function getName(): string
    {
        return 'failing';
    }

    public function export(): array
    {
        return ['enabled' => true];
    }

    public function validate(array $data): void
    {
    }

    public function import(array $data): array
    {
        throw new RuntimeException('provider import failed');
    }
}
