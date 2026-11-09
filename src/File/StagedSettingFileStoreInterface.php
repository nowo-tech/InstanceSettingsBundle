<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

/**
 * A file store whose writes cannot be rolled back with the database (1.7, audit JG-M1): inside a unit opened with
 * {@see self::begin()} it only **stages** its changes (new files written as temporary files, deletes queued) and applies
 * them when the outermost unit ends committed; a unit that ends rolled back discards them, so a failed save, clear,
 * import or seed never changes the files. Units nest: an inner committed unit hands its changes to the outer one.
 *
 * The bundle opens a unit around each of its own transactions ({@see FileStoreTransaction::run()}). A transaction the
 * host opens around a bundle call is not seen: the changes are applied when the bundle's outermost unit commits.
 */
interface StagedSettingFileStoreInterface extends SettingFileStoreInterface
{
    /**
     * Opens a (nested) unit.
     */
    public function begin(): void;

    /**
     * Ends the innermost unit: committed changes go to the outer unit, or are applied when it was the outermost one;
     * rolled-back changes are discarded.
     */
    public function end(bool $committed): void;
}
