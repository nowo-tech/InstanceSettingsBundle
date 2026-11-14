<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection;

use Closure;
use DateTimeZone;
use Nowo\InstanceSettingsBundle\Brand\BrandSection;
use Nowo\InstanceSettingsBundle\File\PathGuard;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\PortabilityEnvelope;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Ui\SectionGroupTabs;
use Nowo\InstanceSettingsBundle\Ui\SectionNavigation;
use Nowo\InstanceSettingsBundle\Ui\SettingsPages;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_diff;
use function array_key_exists;
use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
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

    /** Default URL prefix of the image file route (1.4), outside the admin prefix. */
    public const DEFAULT_FILE_PATH_PREFIX = '/_settings-file';

    /** Default directory of the filesystem file store (1.7). */
    public const DEFAULT_FILES_DIRECTORY = '%kernel.project_dir%/config/instance-files';

    /** Default days a replaced / removed image is kept before `purge-files` deletes it. */
    public const DEFAULT_ORPHAN_RETENTION = 30;

    /** `brand.preset_picker` (1.6): a select of preset ids (default). */
    public const PRESET_PICKER_SELECT = 'select';

    /** `brand.preset_picker` (1.6): theme cards with swatches. */
    public const PRESET_PICKER_CARDS = 'cards';

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
                            ->info('Source manager of the bundle\'s private entity manager (null = the manager that maps the entity; a literal name maps the entities on that manager only, 1.11).')
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
                        ->enumNode('hidden_secrets')
                            ->info('1.9: a secret typed into a provider-dependent row hidden for the submitted provider: "drop" (default: ignored, only an explicit clear deletes) or "keep_typed" (saved whichever provider is selected; never required, its own constraints run). A section implementing SettingsSectionHiddenSecretsInterface overrides it.')
                            ->values(['drop', 'keep_typed'])
                            ->defaultValue('drop')
                        ->end()
                        ->booleanNode('hidden_secrets_notice')
                            ->info('1.9: while hidden secrets are dropped, a section with provider-dependent secrets shows a notice above its form (translation key notice.hidden_secrets_dropped, NowoInstanceSettingsBundle domain).')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('unlayered_styles')
                            ->info('1.13: the bundle stylesheet (colour input, image cards) sits in UiKit\'s cascade layer nowo-ui (default false: nowo-instance-settings.css, so host utilities and unlayered host CSS win). true links nowo-instance-settings.unlayered.css instead, for hosts that link UiKit\'s nowo-ui.unlayered.css.')
                            ->defaultFalse()
                        ->end()
                        ->enumNode('section_layout')
                            ->info('1.14: how a section page lays out its field groups (SettingsSectionGroupsInterface, else the fields\' form groups): "stacked" (default: one column, unchanged markup) or "tabs" (one UiKit subtab per group, ?settings_group=<code> in the URL; one form: Save saves every group). A section with fewer than two groups always renders stacked. SectionEmbedOptions::withSectionLayout() overrides it per embed.')
                            ->values(['stacked', 'tabs'])
                            ->defaultValue('stacked')
                        ->end()
                        ->scalarNode('section_group_parameter')
                            ->info('1.14 (audit MP-L1): query parameter of the open subtab with section_layout: tabs (default "settings_group", namespaced so a host page keeps its own ?group=). Letters, digits and underscores; SectionEmbedOptions::withGroupParameter() overrides it per embed.')
                            ->defaultValue(SectionGroupTabs::QUERY_PARAMETER)
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match(SectionGroupTabs::PARAMETER_PATTERN, $v) !== 1)
                                ->thenInvalid('nowo_instance_settings.web_ui.section_group_parameter must contain only letters, digits and underscores (not starting with a digit), got %s.')
                            ->end()
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
                        ->arrayNode('section_icons')
                            ->info('1.16: tab icon per section name, overriding or filling the section\'s getIcon(), including sections of other bundles. An icon is a canonical UiKit kind ("config") or a prefixed icon name ("tabler:palette"). A name that matches no registered section is ignored (debug log line).')
                            ->useAttributeAsKey('section')
                            ->normalizeKeys(false)
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match(SectionNavigation::ICON_PATTERN, $v) !== 1)
                                    ->thenInvalid('nowo_instance_settings.web_ui.section_icons values must be a canonical UiKit icon kind such as "config" or a prefixed icon name such as "tabler:palette", got %s.')
                                ->end()
                            ->end()
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => self::invalidSectionNames(array_keys($v)) !== [])
                                ->then(static function (array $v): never {
                                    throw new InvalidConfigurationException(sprintf('Invalid nowo_instance_settings.web_ui.section_icons section name(s) "%s": expected lower snake case section names.', implode('", "', self::invalidSectionNames(array_keys($v)))));
                                })
                            ->end()
                        ->end()
                        ->arrayNode('navigation_hidden')
                            ->info('1.16: section names left out of the settings page tab bar and of the "first section" redirect. Presentation only: a hidden section stays registered, guarded by its area and embeddable (SettingsSectionEmbedder), and its own URL keeps working. A name that matches no registered section is ignored (debug log line).')
                            ->scalarPrototype()->end()
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => self::invalidSectionNames($v) !== [])
                                ->then(static function (array $v): never {
                                    throw new InvalidConfigurationException(sprintf('Invalid nowo_instance_settings.web_ui.navigation_hidden section name(s) "%s": expected lower snake case section names.', implode('", "', self::invalidSectionNames($v))));
                                })
                            ->end()
                        ->end()
                        ->booleanNode('reveal_on_toggle')
                            ->info('1.16: on a revealable secret row, the PasswordToggle eye of the "new value" input reveals the STORED secret (the same POST endpoint, CSRF token, rate limiter, access checks and audit as the Reveal button) in a read-only element of the row, never in the input; a second click hides it. Default false: the eye only unmasks what was typed.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('reveal_toggle_only')
                            ->info('1.16: with reveal_on_toggle, hide the separate Reveal / Copy / Hide buttons on rows that have the eye (rows without it keep them). Default false.')
                            ->defaultFalse()
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
                        ->arrayNode('reveal_roles')
                            ->info('1.15: roles that may reveal a stored secret of a field declared revealable (any of them), asked on top of the section\'s own area. Default ROLE_DEVELOPER; an empty list refuses every reveal. Ignored when access_checker is set (the custom checker decides the "_reveal" area).')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_DEVELOPER'])
                        ->end()
                        ->scalarNode('reveal_rate_limiter')
                            ->info('1.15: service id of a Symfony RateLimiterFactory (framework.rate_limiter.<name> gives limiter.<name>) consuming one token per reveal, keyed by the hashed actor; null = no limit.')
                            ->defaultNull()
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
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => array_key_exists(InstanceSettingsAccessCheckerInterface::AREA_REVEAL, $v['area_roles'] ?? []))
                        ->thenInvalid('nowo_instance_settings.security.area_roles must not define "_reveal": the roles of the secret reveal are security.reveal_roles (got %s).')
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
                            ->info('Directory of the filesystem store (files.store: filesystem): <directory>/<section>/<field>.<png|jpg|webp>, variants in <section>/.variants/. Must not be under the public web root. Accepts %env(...)%: the path rules are then applied at run time.')
                            ->defaultValue(self::DEFAULT_FILES_DIRECTORY)
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !self::isPathLike($v))
                                ->thenInvalid('nowo_instance_settings.files.directory must be a local directory path, got %s.')
                            ->end()
                        ->end()
                        ->arrayNode('crop')
                            ->info('Crop step of every image field (1.8, UiKit 2.43 image crop): false (default, off), true, or {shape, quality, labels}. The aspect, sizes, format and max_bytes are derived per field from its rules; SettingField::withCrop() / withoutCrop() win. The server still checks and re-encodes every upload. The section page loads the UiKit image-crop script itself; add img-src blob: to a strict CSP.')
                            ->canBeEnabled()
                            ->children()
                                ->enumNode('shape')
                                    ->info('square (default) or circle (a visual mask: the stored image stays square).')
                                    ->values([null, 'square', 'circle'])
                                    ->defaultNull()
                                ->end()
                                ->floatNode('quality')
                                    ->info('WebP / JPEG encoder quality, 0 to 1 (UiKit default 0.92).')
                                    ->min(0)
                                    ->max(1)
                                    ->defaultNull()
                                ->end()
                                ->arrayNode('labels')
                                    ->info('Dialog texts over the UiKit translations image_crop.* (title, instructions, stage, zoom, zoom_status, apply, cancel, close, too_large, optimizing).')
                                    ->normalizeKeys(false)
                                    ->scalarPrototype()->end()
                                ->end()
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
                            ->info('Every seed file and seed image path must resolve (symlinks followed) inside this directory. Accepts %env(...)%: the path rules are then applied at run time.')
                            ->defaultValue('%kernel.project_dir%')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !self::isPathLike($v))
                                ->thenInvalid('nowo_instance_settings.seed.base_dir must be a local directory path, got %s.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->append($this->brandNode())
                ->append($this->expiringSecretsNode())
            ->end();

        return $treeBuilder;
    }

    /**
     * `expiring_secrets` (1.17, Beacon D-20261114-09): the opt-in expiry dates of the secret fields declaring
     * `expires: true`, their thresholds and the page warning.
     */
    private function expiringSecretsNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('expiring_secrets'))->getRootNode();
        $node
            ->info('Expiring secrets (1.17): an expiry date next to the secret fields declaring expires: true, threshold events and a page warning.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Maps the expiry table (instance_setting_secret_expiry) and registers the store, the read port and nowo:instance-settings:check-expiring-secrets. Off by default: no schema change. A section declaring expires: true without it fails at boot. Literal boolean only.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('thresholds')
                    ->info('Days before the expiry date at which nowo:instance-settings:check-expiring-secrets dispatches SecretExpiryThresholdReachedEvent, once per threshold and date (0 = the day itself and after).')
                    ->integerPrototype()->min(0)->max(3650)->end()
                    ->defaultValue([30, 7, 1, 0])
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => $v === [] || count($v) !== count(array_unique($v)))
                        ->thenInvalid('nowo_instance_settings.expiring_secrets.thresholds must be a non-empty list of distinct day counts, got %s.')
                    ->end()
                ->end()
                ->booleanNode('warning')
                    ->info('The settings page warns on the section and next to an expiring secret within warning_days of its date, or expired.')
                    ->defaultTrue()
                ->end()
                ->integerNode('warning_days')
                    ->info('Days before the date from which the page warns (0 = only on the day and once expired).')
                    ->defaultValue(30)
                    ->min(0)
                    ->max(3650)
                ->end()
                ->scalarNode('timezone')
                    ->info('Zone of "today" for the thresholds and the warning (e.g. "Europe/Madrid"). Null: the PHP default zone.')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || !in_array($v, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)))
                        ->thenInvalid('nowo_instance_settings.expiring_secrets.timezone must be a timezone identifier such as "Europe/Madrid", got %s.')
                    ->end()
                ->end()
            ->end();

        return $node;
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
                ->enumNode('preset_picker')
                    ->info('1.6 (IS-2): how the appearance section shows preset / preset_dark: "select" (a select of preset ids, the default) or "cards" (theme cards: a radio group with a colour swatch and UiKit\'s preset names).')
                    ->values([self::PRESET_PICKER_SELECT, self::PRESET_PICKER_CARDS])
                    ->defaultValue(self::PRESET_PICKER_SELECT)
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
        $node->append($this->errorIllustrationsNode());

        return $node;
    }

    /**
     * `brand.error_illustrations` (1.13): the UiKit error page illustrations from the uploaded brand extra images.
     */
    private function errorIllustrationsNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('error_illustrations'))->getRootNode();
        $node
            ->info('1.13: UiKit error page illustrations from the uploaded brand extra images (service nowo_instance_settings.brand.error_illustration_provider, set it as nowo_ui_kit.error_pages.illustration_provider). For status N: the uploaded extra image error_<N>, else the uploaded base extra image, else UiKit\'s error_pages.illustrations map. Configured defaults never count. Needs files.store: filesystem.')
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('base')
                    ->info('Id of the extra image used for every status without its own uploaded error_<N> image (e.g. mascot). Null = none.')
                    ->defaultNull()
                ->end()
                ->booleanNode('per_code')
                    ->info('Use the uploaded extra images error_<N> (N = 400 to 599) for their status.')
                    ->defaultTrue()
                ->end()
            ->end();

        return $node;
    }

    /**
     * Entries of `$names` that are not valid section names ({@see SettingsSectionRegistry::NAME_PATTERN}).
     *
     * @param array<mixed> $names
     *
     * @return list<string>
     */
    public static function invalidSectionNames(array $names): array
    {
        $invalid = [];
        foreach ($names as $name) {
            if (!is_string($name) || preg_match(SettingsSectionRegistry::NAME_PATTERN, $name) !== 1) {
                $invalid[] = is_string($name) ? $name : get_debug_type($name);
            }
        }

        return $invalid;
    }

    /**
     * Path check of `files.directory` / `seed.base_dir` in the tree (1.7.1): a string without `://` that is not only
     * whitespace. The empty string passes here: while Symfony validates an `%env(...)%` value it hands the closure ''
     * (the placeholder's default), indistinguishable from a literal ''. The extension refuses a literal blank value
     * ({@see NowoInstanceSettingsExtension}), and the store / seeder apply the full rule to the resolved env value.
     */
    public static function isPathLike(mixed $value): bool
    {
        return is_string($value) && ($value === '' || PathGuard::isLocalPath($value));
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
