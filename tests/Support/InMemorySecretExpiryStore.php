<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Expiry\ExpiryDate;
use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryRecord;
use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryStoreInterface;

use function array_values;
use function ksort;

/**
 * In-memory expiry store (unit tests): the same rules as the Doctrine store, shared between "runs" by reusing the
 * instance (a restart keeps the store, as the database would).
 */
final class InMemorySecretExpiryStore implements SecretExpiryStoreInterface
{
    /** @var array<string, SecretExpiryRecord> `section.field` => record */
    public array $records = [];

    public int $writes = 0;

    /** Claims answered false (another run won), counted down. */
    public int $loseClaims = 0;

    public function findAll(): array
    {
        $records = $this->records;
        ksort($records);

        return array_values($records);
    }

    public function findBySection(string $section): array
    {
        $found = [];
        foreach ($this->findAll() as $record) {
            if ($record->section === $section) {
                $found[$record->field] = $record;
            }
        }

        return $found;
    }

    public function write(string $section, array $dates, DateTimeImmutable $at): void
    {
        ++$this->writes;
        foreach ($dates as $field => $date) {
            $key     = $section . '.' . $field;
            $current = $this->records[$key] ?? null;
            if ($date === null) {
                unset($this->records[$key]);

                continue;
            }
            if ($current === null || ExpiryDate::format($current->expiresOn) !== ExpiryDate::format($date)) {
                $this->records[$key] = new SecretExpiryRecord($section, $field, ExpiryDate::of($date));
            }
        }
    }

    public function claim(string $section, string $field, DateTimeImmutable $expiresOn, int $threshold, DateTimeImmutable $at): bool
    {
        $current = $this->records[$section . '.' . $field] ?? null;
        if ($this->loseClaims > 0) {
            --$this->loseClaims;

            return false;
        }
        if ($current === null || ExpiryDate::format($current->expiresOn) !== ExpiryDate::format($expiresOn) || ($current->notifiedThreshold !== null && $current->notifiedThreshold <= $threshold)) {
            return false;
        }
        $this->records[$section . '.' . $field] = new SecretExpiryRecord($section, $field, $current->expiresOn, $threshold);

        return true;
    }

    public function release(string $section, string $field, DateTimeImmutable $expiresOn, int $threshold, ?int $previous): void
    {
        $current = $this->records[$section . '.' . $field] ?? null;
        if ($current !== null && $current->notifiedThreshold === $threshold && ExpiryDate::format($current->expiresOn) === ExpiryDate::format($expiresOn)) {
            $this->records[$section . '.' . $field] = new SecretExpiryRecord($section, $field, $current->expiresOn, $previous);
        }
    }
}
