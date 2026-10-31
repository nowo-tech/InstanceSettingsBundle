<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Throwable;

use function array_values;
use function spl_object_id;

/**
 * In-memory repository counting queries (unit tests).
 */
final class InMemorySettingValueRepository implements SettingValueRepositoryInterface
{
    /** @var array<int, SettingValue> */
    public array $rows = [];

    public int $queries = 0;

    public int $flushes = 0;

    public int $transactions = 0;

    public function findAll(): array
    {
        ++$this->queries;

        return array_values($this->rows);
    }

    public function findBySection(string $section): array
    {
        ++$this->queries;

        return array_values(array_filter($this->rows, static fn (SettingValue $row): bool => $row->getSection() === $section));
    }

    public function apply(array $save, array $remove): void
    {
        foreach ($save as $row) {
            $this->rows[spl_object_id($row)] = $row;
        }
        foreach ($remove as $row) {
            unset($this->rows[spl_object_id($row)]);
        }
        ++$this->flushes;
    }

    public function transactional(callable $callback): mixed
    {
        ++$this->transactions;
        $snapshot = $this->rows;
        try {
            return $callback();
        } catch (Throwable $e) {
            $this->rows = $snapshot;

            throw $e;
        }
    }
}
