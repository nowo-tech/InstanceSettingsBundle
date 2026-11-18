<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeKey;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeStoreInterface;

/**
 * In-memory sent-marker store (unit tests): the same rule as the database primary key, shared between "runs" and
 * "workers" by reusing the instance.
 */
final class InMemoryExpiryNoticeStore implements ExpiryNoticeStoreInterface
{
    /** @var array<string, ExpiryNoticeKey> hash => key */
    public array $markers = [];

    public int $releases = 0;

    public function claim(ExpiryNoticeKey $key, DateTimeImmutable $at): bool
    {
        if (isset($this->markers[$key->hash()])) {
            return false;
        }
        $this->markers[$key->hash()] = $key;

        return true;
    }

    public function release(ExpiryNoticeKey $key): void
    {
        ++$this->releases;
        unset($this->markers[$key->hash()]);
    }
}
