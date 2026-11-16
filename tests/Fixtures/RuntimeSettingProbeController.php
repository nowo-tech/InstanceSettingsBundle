<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

use function implode;
use function is_bool;

/**
 * 1.20: a host page reading a runtime setting through the contract resolver (a worker request).
 */
#[AsController]
final readonly class RuntimeSettingProbeController
{
    public function __construct(
        private RuntimeSettingRegistry $registry,
        private RuntimeSettingResolver $resolver,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $definition = $this->registry->get($id);
        $values     = [];
        foreach ($this->resolver->candidates($definition) as $value) {
            $values[] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return new Response(implode('|', $values));
    }
}
