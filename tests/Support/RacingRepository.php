<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use DateTimeImmutable;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Wraps {@see InMemorySettingValueRepository} to simulate concurrency: on the first `$races` write attempts, another
 * transaction commits `$concurrent` (an admin save) just before the flush, so the flush hits the unique key; the
 * concurrent row survives the rollback. `failOnApply` = n makes the n-th `apply()` fail (rollback without a race).
 */
final class RacingRepository implements SettingValueRepositoryInterface
{
    public int $races = 1;

    public int $attempts = 0;

    public int $failOnApply = 0;

    private int $applies = 0;

    public function __construct(
        private readonly InMemorySettingValueRepository $inner,
        private readonly ?SettingValue $concurrent,
    ) {
    }

    public function findAll(): array
    {
        return $this->inner->findAll();
    }

    public function findBySection(string $section): array
    {
        return $this->inner->findBySection($section);
    }

    public function apply(array $save, array $remove): void
    {
        if (++$this->applies === $this->failOnApply) {
            throw new RuntimeException('disk full');
        }
        if ($this->concurrent instanceof SettingValue && $this->attempts <= $this->races) {
            throw new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('UNIQUE constraint failed')), null);
        }
        $this->inner->apply($save, $remove);
    }

    public function transactional(callable $callback): mixed
    {
        ++$this->attempts;
        $snapshot = $this->inner->rows;
        try {
            return $callback();
        } catch (Throwable $e) {
            $this->inner->rows = $snapshot;
            if ($this->concurrent instanceof SettingValue && $e instanceof UniqueConstraintViolationException) {
                // The other transaction committed: its row survives our rollback.
                $this->inner->apply([$this->concurrent->storeValue('admin.example.test', new DateTimeImmutable())], []);
            }

            throw $e;
        }
    }
}
