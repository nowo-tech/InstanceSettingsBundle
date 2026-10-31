<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection;

use Nowo\InstanceSettingsBundle\Portability\PortabilityEnvelope;
use Nowo\InstanceSettingsBundle\Ui\SettingsPages;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_diff;
use function array_keys;
use function array_values;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function trim;

/**
 * Configuration tree of `nowo_instance_settings` (REQ-SF-006: strict nodes, safe defaults).
 */
final class Configuration implements ConfigurationInterface
{
    /** Extension alias / root key. */
    public const ALIAS = 'nowo_instance_settings';

    /** Default URL prefix of the admin UI. */
    public const DEFAULT_PATH_PREFIX = '/admin/settings';

    /** Default upload / file size cap of imports (1 MiB). */
    public const DEFAULT_MAX_UPLOAD_BYTES = 1_048_576;

    /**
     * Builds the tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        $root
            ->info('Nowo Instance Settings — runtime settings store split into sections, encrypted secrets, admin UI, import / export.')
            ->children()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table_prefix')
                            ->info('Prefix of the bundle table and index names (REQ-ORM-002): "app_" gives app_instance_setting_value.')
                            ->defaultValue('')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[A-Za-z0-9_]*$/', $v) !== 1)
                                ->thenInvalid('nowo_instance_settings.doctrine.table_prefix must contain only letters, digits and underscores, got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('entity_manager')
                            ->info('Doctrine ORM entity manager name (null = default).')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->info('Admin UI (REQ-UI-001 / REQ-UI-003): one UiKit settings page per section + the import / export page.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('false = no admin UI (no routes, no controller); the PHP / Twig / CLI API stays available.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('path_prefix')
                            ->info('URL prefix of the admin routes; protect it with security.access_control in the host.')
                            ->defaultValue(self::DEFAULT_PATH_PREFIX)
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || trim($v, '/ ') === '' || !str_starts_with($v, '/'))
                                ->thenInvalid('nowo_instance_settings.web_ui.path_prefix must be an absolute path such as "/admin/settings", got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('layout_template')
                            ->info('Layout the pages extend. Null (default) = nowo_ui_kit.layouts.admin.')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('templates')
                            ->info('Per-page template overrides (page id => template): section, portability. Extend @!NowoInstanceSettingsBundle/settings/... and override a block.')
                            ->useAttributeAsKey('page')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => array_diff(array_keys($v), array_keys(SettingsPages::TEMPLATES)) !== [])
                                ->then(static function (array $v): never {
                                    throw new InvalidConfigurationException(sprintf('Unknown nowo_instance_settings.web_ui.templates page(s) "%s"; allowed: %s.', implode('", "', array_diff(array_keys($v), array_keys(SettingsPages::TEMPLATES))), implode(', ', array_keys(SettingsPages::TEMPLATES))));
                                })
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->info('REQ-UI-002 private access of the admin UI.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->info('Roles that open the admin UI (any of them). Must not be empty unless access_checker is set or allow_unauthenticated is true.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                        ->arrayNode('area_roles')
                            ->info('Per-area roles replacing access_roles: a section name, or "_portability" for the import / export page.')
                            ->useAttributeAsKey('area')
                            ->normalizeKeys(false)
                            ->arrayPrototype()
                                ->scalarPrototype()->cannotBeEmpty()->end()
                                ->requiresAtLeastOneElement()
                            ->end()
                        ->end()
                        ->scalarNode('access_checker')
                            ->info('Service id implementing InstanceSettingsAccessCheckerInterface; null = role-based default.')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->info('DEV / DEMO only (refused without kernel.debug): open the UI without login.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static function (array $v): bool {
                            if (($v['allow_unauthenticated'] ?? false) === true || (is_string($v['access_checker'] ?? null) && $v['access_checker'] !== '')) {
                                return false;
                            }

                            return self::roles($v['access_roles'] ?? []) === [];
                        })
                        ->thenInvalid('nowo_instance_settings.security.access_roles must contain at least one non-empty role when no access_checker is set and allow_unauthenticated is false (an empty list would deny everybody).')
                    ->end()
                ->end()
                ->arrayNode('portability')
                    ->info('Versioned JSON export / import of the settings and of the tagged portability providers.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('web_ui')
                            ->info('Show the import / export page in the admin UI (the CLI commands are always available).')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('schema')
                            ->info('Envelope "schema" value; imports with another schema are refused.')
                            ->defaultValue(PortabilityEnvelope::DEFAULT_SCHEMA)
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', $v) !== 1)
                                ->thenInvalid('nowo_instance_settings.portability.schema must match ^[a-z0-9][a-z0-9._-]{0,99}$, got %s.')
                            ->end()
                        ->end()
                        ->integerNode('max_upload_bytes')
                            ->info('Size cap of imported files (upload and CLI), in bytes.')
                            ->defaultValue(self::DEFAULT_MAX_UPLOAD_BYTES)
                            ->min(1024)
                            ->max(16_777_216)
                        ->end()
                        ->booleanNode('sealed_secrets')
                            ->info('Allow exporting / importing secrets sealed with the instance DoctrineEncrypt key. false = secrets never leave the instance.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    /**
     * Non-empty role strings of a configured list.
     *
     * @return list<string>
     */
    public static function roles(mixed $roles): array
    {
        $list = [];
        foreach (is_array($roles) ? array_values($roles) : [] as $role) {
            if (is_string($role) && trim($role) !== '') {
                $list[] = trim($role);
            }
        }

        return $list;
    }
}
