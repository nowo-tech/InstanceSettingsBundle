<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed when DoctrineEncryptBundle is not active: without its ORM subscriber the `#[Encrypted]` secret column
 * of the settings table would be written in clear.
 */
final class SecretEncryptionPass implements CompilerPassInterface
{
    /** DoctrineEncrypt ORM subscriber service id. */
    public const SUBSCRIBER_ID = 'nowo_doctrine_encrypt.orm_subscriber';

    /**
     * Throws when the DoctrineEncrypt subscriber is missing.
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->has(self::SUBSCRIBER_ID)) {
            return;
        }

        throw new LogicException('NowoInstanceSettingsBundle stores secret settings encrypted with nowo-tech/doctrine-encrypt-bundle: register Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle in config/bundles.php (before NowoInstanceSettingsBundle) and configure nowo_doctrine_encrypt (key file or secret_key_env_var).');
    }
}
