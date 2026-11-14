<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;

use function array_filter;
use function array_values;

/**
 * 1.18: an in-memory repository whose writes fail with a unique constraint violation, as when a concurrent request
 * inserted the same row first.
 */
final class UniqueViolationRepository implements SettingValueRepositoryInterface
{
    /**
     * @param list<SettingValue> $rows rows found by the reads
     */
    public function __construct(
        private readonly array $rows = [],
    ) {
    }

    public function findAll(): array
    {
        return $this->rows;
    }

    public function findBySection(string $section): array
    {
        return array_values(array_filter($this->rows, static fn (SettingValue $row): bool => $row->getSection() === $section));
    }

    public function apply(array $save, array $remove): void
    {
        throw new UniqueConstraintViolationException(new class('duplicate') extends Exception implements DriverException {
            public function getSQLState(): string
            {
                return '23000';
            }
        }, null);
    }

    public function transactional(callable $callback): mixed
    {
        return $callback();
    }
}
