<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\HostEntity\HostNote;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Throwable;

use function count;

/**
 * Host controller of the worker tests: leaves a host entity pending in the application's entity manager, then saves a
 * setting (or breaks the bundle's write on purpose), and reports what the application manager looks like afterwards.
 */
#[AsController]
final readonly class WorkerProbeController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InstanceSettingsInterface $settings,
        private SettingValueRepositoryInterface $repository,
    ) {
    }

    /**
     * Pending host entity + `save()`.
     */
    public function save(Request $request): JsonResponse
    {
        $this->entityManager->persist(new HostNote('pending ' . $request->query->getString('value')));
        $this->settings->save('general', ['site_name' => $request->query->getString('value')]);

        return $this->report();
    }

    /**
     * A bundle write that fails (duplicate key): it must close only the bundle's private manager.
     */
    public function break(): JsonResponse
    {
        $now = new DateTimeImmutable();
        try {
            $this->repository->apply([
                (new SettingValue('general', 'footer', $now))->storeValue('a', $now),
                (new SettingValue('general', 'footer', $now))->storeValue('b', $now),
            ], []);
        } catch (Throwable) {
        }

        return $this->report();
    }

    private function report(): JsonResponse
    {
        return new JsonResponse([
            'host_open'    => $this->entityManager->isOpen(),
            'host_pending' => count($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions()),
            'host_rows'    => (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'),
            'site_name'    => $this->settings->getString('general.site_name'),
        ]);
    }
}
