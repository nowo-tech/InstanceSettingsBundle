<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\ClassMetadata;

use function is_numeric;
use function is_string;

/**
 * The id of a row the bundle inserts with plain DBAL (1.19): null when the database generates it (IDENTITY,
 * AUTO_INCREMENT), else the next value of the entity's sequence. The ORM maps the ids to a sequence on PostgreSQL with
 * DBAL 3 (no column default), so an INSERT without the id fails there with a NOT NULL violation (audit QM-M3). Stateless.
 */
final class SequenceId
{
    /**
     * The id to insert, or null to let the database generate it.
     *
     * @param ClassMetadata<object> $metadata the entity's mapping
     */
    public static function next(Connection $connection, ClassMetadata $metadata): ?int
    {
        $sequence = $metadata->isIdGeneratorSequence() ? $metadata->sequenceGeneratorDefinition['sequenceName'] ?? null : null;
        if (!is_string($sequence) || $sequence === '') {
            return null;
        }
        $id = $connection->fetchOne($connection->getDatabasePlatform()->getSequenceNextValSQL($sequence));

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * `$row` with the id column set when the id comes from a sequence ({@see self::next()}), else unchanged.
     *
     * @param array<string, mixed> $row column => value of a row to insert
     * @param ClassMetadata<object> $metadata the entity's mapping
     *
     * @return array<string, mixed>
     */
    public static function into(array $row, Connection $connection, ClassMetadata $metadata): array
    {
        $id = self::next($connection, $metadata);
        if ($id !== null) {
            $row[$metadata->getSingleIdentifierColumnName()] = $id;
        }

        return $row;
    }
}
