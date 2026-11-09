<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection\Compiler;

use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * Attaches the filesystem store's DBAL middleware ({@see FileStoreTransactionMiddleware}) only to the connection of the
 * bundle's entity manager (1.7, audit JG-L7): transactions on other connections never delay or discard file changes.
 * Runs before DoctrineBundle's MiddlewaresPass. Without a resolvable entity manager it falls back to the default
 * connection.
 */
final class FileStoreMiddlewarePass implements CompilerPassInterface
{
    /** Container parameter holding `doctrine.entity_manager` (null = default). */
    public const ENTITY_MANAGER_PARAMETER = 'nowo_instance_settings.doctrine.entity_manager';

    /**
     * Rewrites the middleware tag with the `connection` attribute.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FileStoreTransactionMiddleware::class)) {
            return;
        }
        $manager = $container->hasParameter(self::ENTITY_MANAGER_PARAMETER) ? $container->getParameter(self::ENTITY_MANAGER_PARAMETER) : null;
        if (!is_string($manager)) {
            $manager = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : 'default';
        }
        $id         = sprintf('doctrine.orm.%s_entity_manager', is_string($manager) ? $manager : 'default');
        $argument   = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        $connection = $argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1 ? $match[1] : null;
        if ($connection === null) {
            $default    = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;
            $connection = is_string($default) ? $default : 'default';
        }

        $container->getDefinition(FileStoreTransactionMiddleware::class)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $connection]);
    }
}
