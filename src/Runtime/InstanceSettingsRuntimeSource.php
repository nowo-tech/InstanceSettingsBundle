<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Nowo\DoctrineEncryptBundle\Util\EncryptUtil;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;
use function str_ends_with;
use function strlen;
use function substr;

/**
 * 1.20: nowo-tech/runtime-setting-contract source over the instance settings (`runtime_setting.enabled`).
 *
 * - **Ids** `namespace.key` = `section.field`; environment-variable ids are not stored here (RuntimeEnvBundle is).
 * - **Reads** go through DBAL on every call, on the connection of the bundle's private manager (never an identity map,
 *   audit QS-L-1); the version token and the value come from the same connection. Rows are re-checked byte for byte.
 * - **Values**: a plain row is its JSON scalar, as a string (`true` / `false`, `30`, `0.5`, text); a secret row is
 *   decrypted (DoctrineEncrypt default profile, the entity's format) and loses its terminator; a stored secret without
 *   its terminator is unset, like {@see SettingValue::getSecret()}. A ciphertext that does not decrypt (a wrong or lost
 *   key, a tampered row) makes {@see self::read()} throw the cipher's exception, which carries no value: the resolver
 *   applies the setting's `on_error` policy (fail closed: unavailable; fallback: the configured value). No row = unset
 *   (the contract then applies the configured env value or the bundle default, not the section field default).
 * - **Transactions** (audit QZ-L-4): a read inside a host transaction sees that transaction's own uncommitted rows and
 *   token; resolve runtime settings outside the transactions that write the settings (see HIGH-LOAD.md).
 * - **Previous value** of a rotation: returned only before its UTC expiry; an expired one is never decrypted, and one
 *   that does not decrypt is dropped with a warning (class only), so the current value is still served.
 *
 * Stateless.
 */
final readonly class InstanceSettingsRuntimeSource implements RuntimeValueSourceInterface
{
    private LoggerInterface $logger;

    /**
     * @param EncryptUtil $encryptUtil decrypts the secret columns (DoctrineEncrypt default profile, the entity's format)
     */
    public function __construct(
        private RuntimeSettingTables $tables,
        private EncryptUtil $encryptUtil,
        private ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * The committed token of the section.
     */
    public function namespaceVersion(string $namespace): string
    {
        return $this->tables->token($namespace);
    }

    /**
     * The stored value of `section.field` (and the previous one inside its window); null when unset.
     */
    public function read(RuntimeSettingId $id): ?StoredRuntimeValue
    {
        if ($id->isEnvName()) {
            return null;
        }

        $connection = $this->tables->connection();
        $columns    = $this->tables->settingsColumns();
        $row        = $connection->fetchAssociative(sprintf(
            'SELECT %2$s AS section, %3$s AS name, %4$s AS value, %5$s AS secret FROM %1$s WHERE %2$s = ? AND %3$s = ?',
            $columns['table'],
            $columns['section'],
            $columns['name'],
            $columns['value'],
            $columns['secret'],
        ), [$id->namespace, $id->key]);
        if (!is_array($row) || $row['section'] !== $id->namespace || $row['name'] !== $id->key) {
            return null;
        }

        $current = $this->decode($connection, $row['value'], $row['secret']);
        if ($current === null) {
            return null;
        }

        $previous = $this->previous($connection, $id);

        return $previous === null ? new StoredRuntimeValue($current) : new StoredRuntimeValue($current, $previous[0], $previous[1]);
    }

    /**
     * @return array{0: string, 1: DateTimeImmutable}|null
     */
    private function previous(Connection $connection, RuntimeSettingId $id): ?array
    {
        $row = $connection->fetchAssociative(
            sprintf('SELECT section, name, value, secret_value, expires_at FROM %s WHERE section = ? AND name = ?', $this->tables->previousTable()),
            [$id->namespace, $id->key],
        );
        if (!is_array($row) || $row['section'] !== $id->namespace || $row['name'] !== $id->key || !is_string($row['expires_at'])) {
            return null;
        }

        // UTC wall-clock time (audit QT-L-1); an unreadable or past expiry: nothing to decrypt (QT-L-5).
        $expires = DateTimeImmutable::createFromFormat('!' . $connection->getDatabasePlatform()->getDateTimeFormatString(), $row['expires_at'], new DateTimeZone('UTC'));
        $now     = $this->clock?->now() ?? new DateTimeImmutable();
        if (!$expires instanceof DateTimeImmutable || $expires <= $now) {
            return null;
        }

        try {
            $value = $this->decode($connection, $row['value'], $row['secret_value']);
        } catch (Throwable $e) {
            $this->logger->warning('Instance settings: the previous value of {section}.{name} could not be read and is not accepted.', [
                'section'   => $id->namespace,
                'name'      => $id->key,
                'exception' => $e::class,
            ]);

            return null;
        }

        return $value === null ? null : [$value, $expires];
    }

    /**
     * The value of a stored row as a contract string, null when there is none.
     */
    private function decode(Connection $connection, mixed $json, mixed $secret): ?string
    {
        if (is_string($secret)) {
            $plain = $this->encryptUtil->decrypt($secret);
            if (!is_string($plain) || !str_ends_with($plain, SettingValue::SECRET_TERMINATOR)) {
                return null;
            }

            return substr($plain, 0, -strlen(SettingValue::SECRET_TERMINATOR));
        }

        $value = Type::getType(Types::JSON)->convertToPHPValue($json, $connection->getDatabasePlatform());

        return match (true) {
            is_bool($value)   => RuntimeSettingType::Bool->format($value),
            is_int($value)    => RuntimeSettingType::Int->format($value),
            is_float($value)  => RuntimeSettingType::Float->format($value),
            is_string($value) => $value,
            default           => null,
        };
    }
}
