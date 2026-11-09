<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection;

use Closure;
use Nowo\InstanceSettingsBundle\Brand\BrandSection;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\PortabilityEnvelope;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
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
use function mb_strlen;
use function preg_match;
use function sprintf;
use function str_contains;
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

    /** Default URL prefix of the image file route (1.4), outside the admin prefix. */
    public const DEFAULT_FILE_PATH_PREFIX = '/_settings-file';

    /** Default directory of the filesystem file store (1.7). */
    public const DEFAULT_FILES_DIRECTORY = '%kernel.project_dir%/config/instance-files';

    /** Default days a replaced / removed image is kept before `purge-files` deletes it. */
    public const DEFAULT_ORPHAN_RETENTION = 30;

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
                        ->integerNode('max_file_bytes')
                            ->info('Image fields (1.4): cap of the decoded bytes of all the files of one envelope. max_upload_bytes stays the outer bound of the JSON file (raise it for branded instances).')
                            ->defaultValue(ConfigPortability::DEFAULT_MAX_FILE_BYTES)
                            ->min(0)
                            ->max(16_777_216)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('files')
                    ->info('Image setting fields (1.4): opt-in file table, file route and orphan retention.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Maps the file table (instance_setting_file) and registers the file store, route and purge command. Off by default: no schema change. A section declaring an image field without it fails at boot. Literal boolean only.')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('path_prefix')
                            ->info('URL prefix of the image file route; must be outside web_ui.path_prefix (public images must not sit under the admin firewall). Add an access_control PUBLIC_ACCESS line for it.')
                            ->defaultValue(self::DEFAULT_FILE_PATH_PREFIX)
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || trim($v, '/ ') === '' || !str_starts_with($v, '/') || preg_match('#^/[A-Za-z0-9._~/-]*$#', $v) !== 1)
                                ->thenInvalid('nowo_instance_settings.files.path_prefix must be an absolute path such as "/_settings-file", got %s.')
                            ->end()
                        ->end()
                        ->integerNode('orphan_retention')
                            ->info('Days a replaced or removed image stays servable (e-mails already sent keep it) before nowo:instance-settings:purge-files deletes it.')
                            ->defaultValue(self::DEFAULT_ORPHAN_RETENTION)
                            ->min(0)
                            ->max(3650)
                        ->end()
                        ->enumNode('store')
                            ->info('Where the images live (1.7): "doctrine" (default, the instance_setting_file table) or "filesystem" (one stable file per field in files.directory, no database blob; ready to commit next to the seed file, mounted as a persistent volume in production).')
                            ->values(['doctrine', 'filesystem'])
                            ->defaultValue('doctrine')
                        ->end()
                        ->scalarNode('directory')
                            ->info('Directory of the filesystem store (files.store: filesystem): <directory>/<section>/<field>.<png|jpg|webp>, variants in <section>/.variants/. Need not be under the public web root.')
                            ->defaultValue(self::DEFAULT_FILES_DIRECTORY)
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || trim($v) === '' || str_contains($v, '://'))
                                ->thenInvalid('nowo_instance_settings.files.directory must be a local directory path, got %s.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('seed')
                    ->info('Host seed file (1.7): initial values and images loaded fill-only by nowo:instance-settings:seed.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('file')
                            ->info('Default seed file (.yaml, .yml or .json), e.g. "%kernel.project_dir%/config/instance-seed.yaml". Null: the command needs the file argument.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('base_dir')
                            ->info('Every seed file and seed image path must resolve (symlinks followed) inside this directory.')
                            ->defaultValue('%kernel.project_dir%')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || trim($v) === '' || str_contains($v, '://'))
                                ->thenInvalid('nowo_instance_settings.seed.base_dir must be a local directory path, got %s.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->append($this->brandNode())
            ->end();

        return $treeBuilder;
    }

    /**
     * `brand` (1.5, spec 220 S3): the opt-in brand bridge (the `brand` and `appearance` sections, the brand contract
     * provider and the UiKit appearance provider).
     */
    private function brandNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('brand'))->getRootNode();
        $text = static fn (int $max): Closure => static fn (mixed $v): bool => $v !== null && (!is_string($v) || trim($v) === '' || mb_strlen($v) > $max || preg_match(BrandSection::UNSAFE_TEXT_PATTERN, $v) === 1);

        $defaults = $node
            ->info('Brand bridge (1.5): the "brand" and "appearance" sections, nowo-tech/brand-contracts BrandProviderInterface and, when the host set no nowo_ui_kit.appearance.provider, the UiKit appearance provider. Off by default. Implies files.enabled.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Registers the brand and appearance sections and the bridge. Needs nowo-tech/brand-contracts. Literal boolean only.')
                    ->defaultFalse()
                ->end()
                ->scalarNode('public_dir')
                    ->info('Directory the default asset paths are relative to (served by the web server as plain static files).')
                    ->defaultValue('%kernel.project_dir%/public')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('fallback_provider')
                    ->info('Service id implementing Brand\\BrandFallbackProviderInterface: values the two sections seed from (nowo:instance-settings:seed-from-fallbacks). Null = none.')
                    ->defaultNull()
                ->end()
                ->arrayNode('defaults')
                    ->info('Values while nothing is stored. Asset paths are relative to public_dir (committed, trusted files; SVG and ICO allowed).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('name')
                            ->info('Brand name while none is stored (required with enabled: true; at most 80 characters).')
                            ->defaultNull()
                            ->validate()->ifTrue($text(BrandSection::NAME_MAX))->thenInvalid('nowo_instance_settings.brand.defaults.name must be a non-empty string of at most 80 printable characters, got %s.')->end()
                        ->end()
                        ->scalarNode('short_name')
                            ->defaultNull()
                            ->validate()->ifTrue($text(BrandSection::SHORT_NAME_MAX))->thenInvalid('nowo_instance_settings.brand.defaults.short_name must be a non-empty string of at most 12 printable characters, got %s.')->end()
                        ->end()
                        ->arrayNode('eyebrow')
                            ->info('A literal string, or {key, domain} for a translation key (follows the viewer locale while nothing is stored).')
                            ->addDefaultsIfNotSet()
                            ->beforeNormalization()
                                ->ifString()
                                ->then(static fn (string $v): array => ['text' => $v])
                            ->end()
                            ->children()
                                ->scalarNode('text')->defaultNull()
                                    ->validate()->ifTrue($text(BrandSection::EYEBROW_MAX))->thenInvalid('nowo_instance_settings.brand.defaults.eyebrow must be a non-empty string of at most 120 printable characters, got %s.')->end()
                                ->end()
                                ->scalarNode('key')->defaultNull()
                                    ->validate()->ifTrue($text(255))->thenInvalid('nowo_instance_settings.brand.defaults.eyebrow.key must be a non-empty translation key, got %s.')->end()
                                ->end()
                                ->scalarNode('domain')->defaultValue('messages')->cannotBeEmpty()->end()
                            ->end()
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => ($v['text'] ?? null) !== null && ($v['key'] ?? null) !== null)
                                ->thenInvalid('nowo_instance_settings.brand.defaults.eyebrow is a string or {key, domain}, not both.')
                            ->end()
                        ->end()
                        ->scalarNode('wordmark')
                            ->defaultNull()
                            ->validate()->ifTrue($text(BrandSection::WORDMARK_MAX))->thenInvalid('nowo_instance_settings.brand.defaults.wordmark must be a non-empty string of at most 40 printable characters, got %s.')->end()
                        ->end();
        foreach (BrandSection::FIXED_SLOTS as $slot) {
            $defaults->scalarNode($slot)->defaultNull()->end();
        }

        $defaults->end()->end()
                ->arrayNode('extra_images')
                    ->info('Host-declared extra brand images (slot "extra.<id>", field "extra_<id>"), e.g. {mascot: {label: brand.mascot, default: brand/mascot.png}}.')
                    ->useAttributeAsKey('id')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->defaultNull()->end()
                            ->scalarNode('help')->defaultNull()->end()
                            ->integerNode('max_width')->defaultValue(ImageSpec::DEFAULT_MAX_DIMENSION)->min(1)->max(ImageSpec::DEFAULT_MAX_DIMENSION)->end()
                            ->integerNode('max_height')->defaultValue(ImageSpec::DEFAULT_MAX_DIMENSION)->min(1)->max(ImageSpec::DEFAULT_MAX_DIMENSION)->end()
                            ->integerNode('max_bytes')->defaultValue(ImageSpec::DEFAULT_MAX_BYTES)->min(1)->max(ImageSpec::MAX_BYTES_CEILING)->end()
                            ->booleanNode('square')->defaultFalse()->end()
                            ->scalarNode('default')->defaultNull()->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static function (array $v): bool {
                            foreach (array_keys($v) as $id) {
                                if (preg_match(BrandSection::EXTRA_ID_PATTERN, (string) $id) !== 1) {
                                    return true;
                                }
                            }

                            return false;
                        })
                        ->thenInvalid('nowo_instance_settings.brand.extra_images ids must match ' . BrandSection::EXTRA_ID_PATTERN . ', got %s.')
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => ($v['enabled'] ?? false) === true && ($v['defaults']['name'] ?? null) === null)
                ->thenInvalid('nowo_instance_settings.brand.defaults.name is required when brand.enabled is true (the brand name while none is stored).')
            ->end();

        return $node;
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
