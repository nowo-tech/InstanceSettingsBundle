<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function in_array;

/**
 * Kernel of the integration tests: Framework, Security (http_basic, in-memory users), Twig + Twig Extra, Doctrine on
 * in-memory SQLite, DoctrineEncrypt (Halite key in the cache dir), FormKit, UiKit and the bundle. Configuration and
 * routes are injected per test.
 */
final class InstanceSettingsTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param Closure(ContainerConfigurator): void $services extension configs and services
     * @param Closure(RoutingConfigurator): void $routes routes
     * @param list<string> $without bundle short names to leave out (security, doctrine_encrypt; security_off = a `security: false` firewall)
     * @param bool $hostDefaultDriver the host gives its default manager's chain a catch-all default driver (what
     *                                DoctrineBundle 3.4 `auto_discover_entities` does)
     */
    public function __construct(
        private readonly string $id,
        private readonly Closure $services,
        private readonly Closure $routes,
        private readonly array $without = [],
        bool $debug = false,
        private readonly bool $hostDefaultDriver = false,
    ) {
        parent::__construct('test', $debug);
    }

    protected function build(ContainerBuilder $container): void
    {
        if (!$this->hostDefaultDriver) {
            return;
        }

        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                // An attribute driver answers "not transient" for any class carrying #[ORM\Entity], whatever its paths.
                $container->getDefinition('doctrine.orm.default_metadata_driver')
                    ->addMethodCall('setDefaultDriver', [new Definition(AttributeDriver::class, [[dirname(__DIR__, 2) . '/Fixtures/HostEntity'], true])]);
            }
        });
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if (!in_array('security', $this->without, true)) {
            yield new SecurityBundle();
        }
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new DoctrineBundle();
        if (!in_array('doctrine_encrypt', $this->without, true)) {
            yield new NowoDoctrineEncryptBundle();
        }
        yield new NowoFormKitBundle();
        yield new NowoUiKitBundle();
        yield new NowoInstanceSettingsBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-instance-settings-tests';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'test-secret',
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'csrf_protection'       => true,
            'form'                  => ['csrf_protection' => true],
            'validation'            => true,
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'translator'            => ['default_path' => '%kernel.project_dir%/tests/Fixtures/translations', 'fallbacks' => ['en']],
            'router'                => ['utf8' => true],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
            'orm'  => [],
        ]);
        if (!in_array('doctrine_encrypt', $this->without, true)) {
            $container->extension('nowo_doctrine_encrypt', [
                'default_profile'      => 'default',
                'secret_permissions'   => ['enabled' => false],
                'generate_missing_key' => true,
                'on_decrypt_failure'   => 'null',
                'profiles'             => ['default' => ['encryptor_class' => 'Halite', 'secret_directory_path' => self::baseDir() . '/' . $this->id . '/keys']],
            ]);
        }
        if (!in_array('security', $this->without, true)) {
            $container->extension('security', [
                'password_hashers' => ['Symfony\Component\Security\Core\User\InMemoryUser' => 'plaintext'],
                'providers'        => ['memory' => ['memory' => ['users' => [
                    'admin'  => ['password' => 'admin', 'roles' => ['ROLE_ADMIN']],
                    'super'  => ['password' => 'super', 'roles' => ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']],
                    'viewer' => ['password' => 'viewer', 'roles' => ['ROLE_USER']],
                    // 1.15: may open the UI and reveal a revealable secret (security.reveal_roles default).
                    'developer' => ['password' => 'developer', 'roles' => ['ROLE_ADMIN', 'ROLE_DEVELOPER']],
                ]]]],
                'firewalls' => in_array('security_off', $this->without, true)
                    ? ['main' => ['security' => false]]
                    : ['main' => ['lazy' => true, 'http_basic' => true]],
            ]);
        }
        $container->services()->set('logger', NullLogger::class);
        ($this->services)($container);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        ($this->routes)($routes);
    }
}
