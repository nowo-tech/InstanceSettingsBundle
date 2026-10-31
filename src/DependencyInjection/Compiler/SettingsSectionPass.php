<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection\Compiler;

use Nowo\InstanceSettingsBundle\Attribute\AsSettingsSection;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function count;
use function is_int;
use function max;

/**
 * Collapses several `nowo_instance_settings.section` tags of one service (interface autoconfiguration + the
 * {@see AsSettingsSection} attribute + a YAML tag) into one tag with the highest
 * priority, so each section is listed once.
 */
final class SettingsSectionPass implements CompilerPassInterface
{
    /**
     * Normalizes the section tags.
     */
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds(NowoInstanceSettingsBundle::SECTION_TAG) as $id => $tags) {
            if (count($tags) < 2) {
                continue;
            }

            $priority = null;
            foreach ($tags as $attributes) {
                $value = $attributes['priority'] ?? null;
                if (is_int($value)) {
                    $priority = $priority === null ? $value : max($priority, $value);
                }
            }

            $definition = $container->getDefinition($id);
            $definition->clearTag(NowoInstanceSettingsBundle::SECTION_TAG);
            $definition->addTag(NowoInstanceSettingsBundle::SECTION_TAG, $priority === null ? [] : ['priority' => $priority]);
        }
    }
}
