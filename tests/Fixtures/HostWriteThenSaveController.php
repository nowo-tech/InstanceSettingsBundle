<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * A controller of the bundle's namespace whose request first writes in autocommit outside the bundle's runner (what a host
 * listener or another bundle does), then saves a section. If that save meets a lock conflict, the commit made earlier in
 * the request must give `CONFLICT_002` (request-level detection).
 */
#[AsController]
final readonly class HostWriteThenSaveController
{
    public function __construct(private Connection $connection, private InstanceSettingsInterface $settings)
    {
    }

    public function __invoke(): JsonResponse
    {
        $this->connection->executeStatement('UPDATE instance_setting_value SET value = ? WHERE section = ? AND name = ?', ['"Host"', 'mailer', 'from_name']);
        $this->settings->save('general', ['site_name' => 'Second']);

        return new JsonResponse(['saved' => true]);
    }
}
