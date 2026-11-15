<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Repository\StoredSecretStateInterface;
use Throwable;

use function array_values;
use function spl_object_id;

/**
 * In-memory repository counting queries (unit tests). 1.19: gives a stored "ciphertext" per secret write (a new one on
 * every write, like DoctrineEncrypt), or the plaintext with {@see self::$plainStates} (an unencrypted store).
 */
final class InMemorySettingValueRepository implements SettingValueRepositoryInterface, StoredSecretStateInterface
{
    /** @var array<int, SettingValue> */
    public array $rows = [];

    /** @var array<int, int> object id => write count of a secret row */
    private array $writes = [];

    public bool $plainStates = false;

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
            $this->rows[spl_object_id($row)]   = $row;
            $this->writes[spl_object_id($row)] = ($this->writes[spl_object_id($row)] ?? 0) + 1;
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

    public function storedSecretStates(string $section, bool $forUpdate = false): array
    {
        $states = [];
        foreach ($this->rows as $id => $row) {
            if ($row->getSection() === $section && $row->getSecretValue() !== null) {
                $states[$row->getName()] = $this->plainStates ? $row->getSecretValue() : 'cipher-' . $id . '-' . ($this->writes[$id] ?? 0);
            }
        }

        return $states;
    }
}
