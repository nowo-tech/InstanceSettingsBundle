<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use Nowo\InstanceSettingsBundle\Portability\PortabilityProviderInterface;

use function array_keys;
use function is_string;
use function preg_match;

/**
 * Host data stored elsewhere (in memory here) that joins the export envelope.
 */
class AppearancePortabilityProvider implements PortabilityProviderInterface
{
    /** @var array<string, mixed> */
    public array $data = ['color' => '#112233'];

    public function getName(): string
    {
        return 'appearance';
    }

    public function export(): array
    {
        return $this->data;
    }

    public function validate(array $data): void
    {
        $color = $data['color'] ?? null;
        if (!is_string($color) || preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
            throw new PortabilityException('invalid_value:appearance.color');
        }
    }

    public function import(array $data): array
    {
        $this->data = $data;

        return array_keys($data);
    }
}
