<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use function array_map;

/**
 * Default {@see SettingRowReaderInterface}: maps the rows of the aliased {@see SettingValueRepositoryInterface} to
 * {@see StoredSettingRow}s (1.10). Stateless (worker-safe).
 */
final readonly class RepositorySettingRowReader implements SettingRowReaderInterface
{
    /**
     * @param SettingValueRepositoryInterface $repository storage of the rows
     */
    public function __construct(
        private SettingValueRepositoryInterface $repository,
    ) {
    }

    /**
     * Every stored row as a view.
     */
    public function findAllRows(): array
    {
        return array_map(StoredSettingRow::fromEntity(...), $this->repository->findAll());
    }

    /**
     * Rows of one section as views.
     */
    public function findRowsBySection(string $section): array
    {
        return array_map(StoredSettingRow::fromEntity(...), $this->repository->findBySection($section));
    }
}
