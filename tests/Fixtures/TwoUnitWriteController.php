<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * 1.22 (REQ-RUNTIME-003 rule 6): a controller of the bundle's namespace that runs two write units in one request: the
 * first one commits, the second one may meet a lock conflict. The conflict answer must then be `CONFLICT_002`.
 */
#[AsController]
final readonly class TwoUnitWriteController
{
    public function __construct(private InstanceSettingsInterface $settings)
    {
    }

    /**
     * Saves the mailer section (committed), then the general section.
     */
    public function __invoke(): JsonResponse
    {
        $this->settings->save('mailer', ['from_name' => 'Committed']);
        $this->settings->save('general', ['site_name' => 'Second']);

        return new JsonResponse(['saved' => true]);
    }
}
