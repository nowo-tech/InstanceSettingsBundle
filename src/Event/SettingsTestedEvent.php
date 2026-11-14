<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Event;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestRunner;
use Nowo\InstanceSettingsBundle\SectionTest\TestStatus;

/**
 * Record of one section test (1.18, {@see SectionTestRunner}): dispatched through the PSR-14 dispatcher after the test
 * ran and its report was stored.
 *
 * Names and the outcome only, never a value (REQ-OBS-001): the section, the group, the overall status, the actor and the
 * time. The checks stay in the stored report.
 */
final readonly class SettingsTestedEvent
{
    /**
     * @param string $section section name
     * @param string|null $group field group code (null = the whole section)
     * @param TestStatus $status overall status of the report
     * @param string|null $actor user identifier of the actor (null = none resolvable, e.g. `security.allow_unauthenticated`)
     * @param DateTimeImmutable $testedAt time of the test
     */
    public function __construct(
        public string $section,
        public ?string $group,
        public TestStatus $status,
        public ?string $actor,
        public DateTimeImmutable $testedAt,
    ) {
    }
}
