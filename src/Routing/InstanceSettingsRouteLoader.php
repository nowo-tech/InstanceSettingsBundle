<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Routing;

use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\File\SettingFileController;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Resource\ReflectionClassResource;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function is_string;
use function rtrim;
use function str_starts_with;

/**
 * Loads the admin routes (`type: nowo_instance_settings`) when the web UI is enabled.
 *
 * The routes are declared with #[Route] on {@see SettingsController} (path relative to the prefix, name, methods,
 * requirements); this loader reads those attributes and prepends `web_ui.path_prefix`, so the prefix stays configurable
 * while the definitions live on the controller (REQ-SF-004). Import / export routes are skipped when
 * `portability.web_ui` is false.
 *
 * The image file route ({@see InstanceSettingsRoutes::FILE}, 1.4) is added under `files.path_prefix` only when a section
 * declares an image field, whether the admin UI is enabled or not; hosts without image fields get exactly the routes
 * of 1.3. The section classes are tracked as resources, so adding the first image field rebuilds the router cache.
 */
final class InstanceSettingsRouteLoader extends Loader
{
    /** Routing resource type. */
    public const TYPE = 'nowo_instance_settings';

    private bool $loaded = false;

    /**
     * @param bool $webUiEnabled `web_ui.enabled`
     * @param string $pathPrefix `web_ui.path_prefix`
     * @param bool $portabilityEnabled `portability.web_ui`
     * @param SettingsSectionRegistry|null $registry sections (image fields decide the file route)
     * @param string $filePathPrefix `files.path_prefix`
     */
    public function __construct(
        private readonly bool $webUiEnabled,
        private readonly string $pathPrefix,
        private readonly bool $portabilityEnabled = true,
        ?string $env = null,
        private readonly ?SettingsSectionRegistry $registry = null,
        private readonly string $filePathPrefix = '/_settings-file',
    ) {
        parent::__construct($env);
    }

    /**
     * Builds the route collection (once per router warm-up).
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('Do not add the "nowo_instance_settings" route loader twice.');
        }
        // @igor-ignore - Route loader builds routes at cache warm-up; not per-request state.
        $this->loaded = true;

        $collection = new RouteCollection();
        $this->addFileRoute($collection);
        if (!$this->webUiEnabled) {
            return $collection;
        }

        $prefix = rtrim($this->pathPrefix, '/');
        foreach ((new ReflectionClass(SettingsController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                $declared = $attribute->newInstance();
                $path     = is_string($declared->path) ? $declared->path : '';
                if (!$this->portabilityEnabled && str_starts_with($path, '/portability')) {
                    continue;
                }
                $collection->add((string) $declared->name, new Route(
                    $prefix . $path,
                    ['_controller' => SettingsController::class . '::' . $method->getName()],
                    $declared->requirements,
                    methods: $declared->methods,
                ));
            }
        }

        return $collection;
    }

    private function addFileRoute(RouteCollection $collection): void
    {
        if (!$this->registry instanceof SettingsSectionRegistry) {
            return;
        }
        foreach ($this->registry->all() as $section) {
            $collection->addResource(new ReflectionClassResource(new ReflectionClass($section)));
        }
        if (!$this->registry->hasImageFields()) {
            return;
        }
        $collection->add(InstanceSettingsRoutes::FILE, new Route(
            rtrim($this->filePathPrefix, '/') . '/{section}/{field}/{file}.{ext}',
            ['_controller' => SettingFileController::class],
            [
                'section' => '[a-z][a-z0-9_]{0,63}',
                'field'   => '[a-z][a-z0-9_]{0,63}',
                'file'    => SettingFileController::FILE_REQUIREMENT,
                'ext'     => SettingFileController::EXT_REQUIREMENT,
            ],
            methods: ['GET', 'HEAD'],
        ));
    }

    /**
     * Supports the `nowo_instance_settings` type.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
