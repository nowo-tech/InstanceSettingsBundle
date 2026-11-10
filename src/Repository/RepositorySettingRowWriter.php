<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;

use function count;
use function explode;
use function sprintf;

/**
 * Default {@see SettingRowWriterInterface}: maps the rows onto the entities of the aliased
 * {@see SettingValueRepositoryInterface} and applies them in one of its transactions (1.11). Stateless (worker-safe).
 */
final readonly class RepositorySettingRowWriter implements SettingRowWriterInterface
{
    /**
     * @param SettingValueRepositoryInterface $repository storage of the rows
     */
    public function __construct(
        private SettingValueRepositoryInterface $repository,
    ) {
    }

    /**
     * Inserts / replaces and deletes the rows in one transaction of the repository.
     */
    public function writeRows(array $save, array $remove = []): void
    {
        $plans = [];
        foreach ($save as $row) {
            if ($row->isSecret && $row->secret() === null) {
                throw new InvalidArgumentException(sprintf('Instance setting row "%s" is a secret without its value (unserialized or not decrypted): it cannot be written.', $row->key()));
            }
            $plans[$row->section]['save'][$row->name] = $row;
        }
        foreach ($remove as $key) {
            $parts = explode('.', $key);
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw new InvalidArgumentException(sprintf('Invalid instance setting key "%s": expected "section.field".', $key));
            }
            $plans[$parts[0]]['remove'][$parts[1]] = true;
        }
        if ($plans === []) {
            return;
        }

        // @igor-ignore - The closure runs synchronously inside the transaction and is not stored.
        $this->repository->transactional(function () use ($plans): void {
            $entities = [];
            $deleted  = [];
            foreach ($plans as $section => $plan) {
                $existing = [];
                foreach ($this->repository->findBySection($section) as $entity) {
                    $existing[$entity->getName()] = $entity;
                }
                foreach ($plan['save'] ?? [] as $name => $row) {
                    $entity     = $existing[$name] ?? new SettingValue($section, $name, $row->updatedAt);
                    $entities[] = $row->isSecret ? $entity->storeSecret((string) $row->secret(), $row->updatedAt) : $entity->storeValue($row->value, $row->updatedAt);
                }
                foreach ($plan['remove'] ?? [] as $name => $unused) {
                    if (isset($existing[$name]) && !isset($plan['save'][$name])) {
                        $deleted[] = $existing[$name];
                    }
                }
            }
            $this->repository->apply($entities, $deleted);
        });
    }
}
