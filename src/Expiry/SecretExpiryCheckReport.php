<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry;

use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;

/**
 * Outcome of one {@see SecretExpiryChecker::check()} run (1.17): the thresholds announced (or due, in a dry run) and the
 * `section.field` keys whose announcement failed (retried by the next run). Names, dates and numbers only.
 */
final readonly class SecretExpiryCheckReport
{
    /**
     * @param list<SecretExpiryThresholdReachedEvent> $announced events dispatched (a dry run: due, not dispatched)
     * @param list<string> $failed `section.field` keys whose listener failed
     * @param bool $dryRun nothing was recorded or dispatched
     */
    public function __construct(
        public array $announced,
        public array $failed = [],
        public bool $dryRun = false,
    ) {
    }
}
