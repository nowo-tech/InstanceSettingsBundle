<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use LogicException;
use Nowo\InstanceSettingsBundle\Attribute\AsSettingsTester;
use Nowo\InstanceSettingsBundle\Section\SectionGroups;
use Nowo\InstanceSettingsBundle\Section\SettingsGroup;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Psr\Container\ContainerInterface;

use function array_key_exists;
use function array_keys;
use function implode;
use function sprintf;

/**
 * The registered section testers (1.18, {@see AsSettingsTester}): which section and group has one, and the tester
 * itself, fetched lazily from a service locator.
 *
 * The declarations are checked against the sections on first use (the section must be registered, the group must be
 * one of its field groups), and fail closed with a message naming the tester. Names coming from a request are compared
 * byte for byte (`===`) with the declared ones (REQ-SEC-006).
 *
 * Stateless: the map is built by the container; nothing about a request is kept.
 */
final readonly class SectionTesterRegistry
{
    /**
     * @param list<array{section: string, group: string|null, id: string}> $testers declared testers (service ids)
     * @param ContainerInterface|null $locator service locator of the testers (keyed by service id)
     * @param SettingsSectionRegistry $registry the sections (validation)
     */
    public function __construct(
        private array $testers,
        private ?ContainerInterface $locator,
        private SettingsSectionRegistry $registry,
    ) {
    }

    /**
     * Whether any tester is registered.
     */
    public function isEmpty(): bool
    {
        return $this->testers === [];
    }

    /**
     * The tested groups of `$section`, in registration order: null for a section tester, else the group code.
     *
     * @return list<string|null>
     */
    public function groupsOf(string $section): array
    {
        $groups = [];
        foreach ($this->declared() as $tester) {
            if ($tester['section'] === $section) {
                $groups[] = $tester['group'];
            }
        }

        return $groups;
    }

    /**
     * Whether `$section` (and `$group`, null = the section tester) has a tester; the names are matched byte for byte.
     */
    public function has(string $section, ?string $group): bool
    {
        return $this->find($section, $group) !== null;
    }

    /**
     * The tester of `$section` / `$group`, or null.
     */
    public function get(string $section, ?string $group): ?SettingsSectionTesterInterface
    {
        $id = $this->find($section, $group);
        if ($id === null || !$this->locator instanceof ContainerInterface) {
            return null;
        }
        $tester = $this->locator->get($id);
        if (!$tester instanceof SettingsSectionTesterInterface) {
            throw new LogicException(sprintf('The section tester service "%s" must implement %s.', $id, SettingsSectionTesterInterface::class));
        }

        return $tester;
    }

    /**
     * The group of `$section` with that code (its resolved fields), or null for a section test.
     */
    public function group(string $section, ?string $group): ?SettingsGroup
    {
        if ($group === null) {
            return null;
        }

        return $this->groupsByCode($section)[$group] ?? null;
    }

    private function find(string $section, ?string $group): ?string
    {
        foreach ($this->declared() as $tester) {
            if ($tester['section'] === $section && $tester['group'] === $group) {
                return $tester['id'];
            }
        }

        return null;
    }

    /**
     * The declarations, checked against the registered sections and their groups.
     *
     * @return list<array{section: string, group: string|null, id: string}>
     */
    private function declared(): array
    {
        foreach ($this->testers as $tester) {
            if (!$this->registry->has($tester['section'])) {
                throw new LogicException(sprintf('The section tester "%s" tests the settings section "%s", which is not registered.', $tester['id'], $tester['section']));
            }
            if ($tester['group'] !== null && !array_key_exists($tester['group'], $this->groupsByCode($tester['section']))) {
                throw new LogicException(sprintf('The section tester "%s" tests the group "%s" of the settings section "%s", which has no such group (known: "%s").', $tester['id'], $tester['group'], $tester['section'], implode('", "', array_keys($this->groupsByCode($tester['section'])))));
            }
        }

        return $this->testers;
    }

    /**
     * Every group of a section, by code: the resolved groups (declared, or one per 1.5 form group) whatever the layout;
     * none for a section with fewer than two groups (its page has no subtabs).
     *
     * @return array<string, SettingsGroup>
     */
    private function groupsByCode(string $section): array
    {
        $groups = [];
        foreach (SectionGroups::resolve($this->registry->get($section), $this->registry->fields($section)) as $group) {
            $groups[$group->code] = $group;
        }

        return $groups;
    }
}
