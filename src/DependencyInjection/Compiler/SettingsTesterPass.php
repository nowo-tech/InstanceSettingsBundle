<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\InstanceSettingsBundle\Attribute\AsSettingsTester;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRouteLoader;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTesterRegistry;
use Nowo\InstanceSettingsBundle\SectionTest\SettingsSectionTesterInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;
use function is_subclass_of;
use function preg_match;
use function sprintf;

/**
 * Collects the section testers (1.18, tag `nowo_instance_settings.tester`, set by {@see AsSettingsTester}) into
 * {@see SectionTesterRegistry}: the declared section / group of each, and a service locator of the testers.
 *
 * Fails the build for a tag without a valid section name, an invalid group code, a service that does not implement
 * {@see SettingsSectionTesterInterface} or two testers of the same section and group. Whether the section and the group
 * exist is checked on first use (the sections are runtime services). With at least one tester the test route is
 * mounted ({@see InstanceSettingsRouteLoader::SECTION_TESTS_PARAMETER}); without any, the container and the routes are those of 1.17.
 */
final class SettingsTesterPass implements CompilerPassInterface
{
    /**
     * Fills the tester registry and switches the test route on when a tester is registered.
     */
    public function process(ContainerBuilder $container): void
    {
        $testers = [];
        $refs    = [];
        $seen    = [];
        foreach ($container->findTaggedServiceIds(NowoInstanceSettingsBundle::TESTER_TAG) as $id => $tags) {
            $class = $container->getDefinition($id)->getClass();
            $class = is_string($class) ? $container->getParameterBag()->resolveValue($class) : null;
            if (is_string($class) && !is_subclass_of($class, SettingsSectionTesterInterface::class)) {
                throw new LogicException(sprintf('The section tester service "%s" (%s) must implement %s.', $id, $class, SettingsSectionTesterInterface::class));
            }
            foreach ($tags as $attributes) {
                $section = $attributes['section'] ?? null;
                $group   = $attributes['group'] ?? null;
                if (!is_string($section) || preg_match(SettingsSectionRegistry::NAME_PATTERN, $section) !== 1) {
                    throw new LogicException(sprintf('The section tester service "%s" needs a valid "section" (lower snake case matching %s): use #[AsSettingsTester(section: \'...\')].', $id, SettingsSectionRegistry::NAME_PATTERN));
                }
                if ($group !== null && (!is_string($group) || preg_match(SettingField::GROUP_PATTERN, $group) !== 1)) {
                    throw new LogicException(sprintf('The section tester service "%s" has an invalid "group" (expected a group code matching %s, or none).', $id, SettingField::GROUP_PATTERN));
                }
                $key = $section . "\0" . ($group ?? '');
                if (isset($seen[$key])) {
                    throw new LogicException(sprintf('Two section testers for the settings section "%s"%s: "%s" and "%s" (at most one per section and group).', $section, $group === null ? '' : sprintf(' group "%s"', $group), $seen[$key], $id));
                }
                $seen[$key] = $id;
                $testers[]  = ['section' => $section, 'group' => $group, 'id' => (string) $id];
                $refs[$id]  = new Reference($id);
            }
        }

        $container->setParameter(InstanceSettingsRouteLoader::SECTION_TESTS_PARAMETER, $testers !== []);
        if (!$container->hasDefinition(SectionTesterRegistry::class)) {
            return;
        }
        $container->getDefinition(SectionTesterRegistry::class)
            ->replaceArgument(0, $testers)
            ->replaceArgument(1, $refs === [] ? null : ServiceLocatorTagPass::register($container, $refs));
    }
}
