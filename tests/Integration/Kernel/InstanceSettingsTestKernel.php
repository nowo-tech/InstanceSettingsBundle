<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
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
     * @param list<string> $without bundle short names to leave out (security, doctrine_encrypt)
     */
    public function __construct(
        private readonly string $id,
        private readonly Closure $services,
        private readonly Closure $routes,
        private readonly array $without = [],
        bool $debug = false,
    ) {
        parent::__construct('test', $debug);
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
                'default_profile'    => 'default',
                'secret_permissions' => ['enabled' => false],
                'profiles'           => ['default' => ['encryptor_class' => 'Halite', 'secret_directory_path' => self::baseDir() . '/' . $this->id . '/keys']],
            ]);
        }
        if (!in_array('security', $this->without, true)) {
            $container->extension('security', [
                'password_hashers' => ['Symfony\Component\Security\Core\User\InMemoryUser' => 'plaintext'],
                'providers'        => ['memory' => ['memory' => ['users' => [
                    'admin'  => ['password' => 'admin', 'roles' => ['ROLE_ADMIN']],
                    'super'  => ['password' => 'super', 'roles' => ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']],
                    'viewer' => ['password' => 'viewer', 'roles' => ['ROLE_USER']],
                ]]]],
                'firewalls' => ['main' => ['lazy' => true, 'http_basic' => true]],
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
