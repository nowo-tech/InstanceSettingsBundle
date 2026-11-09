<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Throwable;

/**
 * Runs a callback in a database transaction of the setting repository and, for a {@see StagedSettingFileStoreInterface},
 * in a file unit around it: the file changes are applied only once the transaction committed, discarded when it
 * rolled back (1.7, audit JG-M1). Stateless (worker-safe).
 */
final class FileStoreTransaction
{
    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function run(SettingValueRepositoryInterface $repository, ?SettingFileStoreInterface $store, callable $callback): mixed
    {
        if (!$store instanceof StagedSettingFileStoreInterface) {
            return $repository->transactional($callback);
        }
        $store->begin();
        try {
            $result = $repository->transactional($callback);
        } catch (Throwable $e) {
            $store->end(false);

            throw $e;
        }
        $store->end(true);

        return $result;
    }
}
