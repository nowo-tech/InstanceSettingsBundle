<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\SecretEncryptionPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\SettingsSectionPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\UnauthenticatedAccessPass;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;

final class CompilerPassesTest extends TestCase
{
    public function testCsrfPassFailsClosed(): void
    {
        $container = new ContainerBuilder();
        (new CsrfProtectionPass())->process($container);
        $this->addToAssertionCount(1); // no controller: nothing to protect

        $container->setDefinition(SettingsController::class, new Definition(SettingsController::class));
        $container->setDefinition('security.csrf.token_manager', new Definition('stdClass'));
        $container->setDefinition('form.type_extension.csrf', new Definition('stdClass'));
        (new CsrfProtectionPass())->process($container);

        $container->setParameter('form.type_extension.csrf.enabled', false);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires CSRF protection');
        (new CsrfProtectionPass())->process($container);
    }

    public function testCsrfPassWithoutTokenManager(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(SettingsController::class, new Definition(SettingsController::class));

        $this->expectException(LogicException::class);
        (new CsrfProtectionPass())->process($container);
    }

    public function testUnauthenticatedPassOnlyInDebug(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(UnauthenticatedAccessPass::PARAMETER, true);
        (new UnauthenticatedAccessPass())->process($container); // no controller

        $container->setDefinition(SettingsController::class, new Definition(SettingsController::class));
        $container->setParameter('kernel.debug', true);
        (new UnauthenticatedAccessPass())->process($container);

        $container->setParameter(UnauthenticatedAccessPass::PARAMETER, false);
        $container->setParameter('kernel.debug', false);
        (new UnauthenticatedAccessPass())->process($container);
        $this->addToAssertionCount(3);

        $container->setParameter(UnauthenticatedAccessPass::PARAMETER, true);
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('only allowed when kernel.debug is true');
        (new UnauthenticatedAccessPass())->process($container);
    }

    public function testSecretEncryptionPass(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(SecretEncryptionPass::SUBSCRIBER_ID, new Definition('stdClass'));
        (new SecretEncryptionPass())->process($container);
        $this->addToAssertionCount(1);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('register Nowo\DoctrineEncryptBundle\NowoDoctrineEncryptBundle');
        (new SecretEncryptionPass())->process(new ContainerBuilder());
    }

    public function testSectionPassCollapsesTags(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('a', (new Definition('stdClass'))->addTag(NowoInstanceSettingsBundle::SECTION_TAG)->addTag(NowoInstanceSettingsBundle::SECTION_TAG, ['priority' => 5])->addTag(NowoInstanceSettingsBundle::SECTION_TAG, ['priority' => 9]));
        $container->setDefinition('b', (new Definition('stdClass'))->addTag(NowoInstanceSettingsBundle::SECTION_TAG)->addTag(NowoInstanceSettingsBundle::SECTION_TAG));
        $container->setDefinition('c', (new Definition('stdClass'))->addTag(NowoInstanceSettingsBundle::SECTION_TAG, ['priority' => 1]));

        (new SettingsSectionPass())->process($container);

        self::assertSame([['priority' => 9]], $container->getDefinition('a')->getTag(NowoInstanceSettingsBundle::SECTION_TAG));
        self::assertSame([[]], $container->getDefinition('b')->getTag(NowoInstanceSettingsBundle::SECTION_TAG));
        self::assertSame([['priority' => 1]], $container->getDefinition('c')->getTag(NowoInstanceSettingsBundle::SECTION_TAG));
    }

    public function testTwigPathsPass(): void
    {
        $views = dirname(__DIR__, 4) . '/src/Resources/views';

        $none = new ContainerBuilder();
        (new TwigPathsPass())->process($none);
        $this->addToAssertionCount(1);

        $project = sys_get_temp_dir() . '/nowo-instance-settings-twig-' . bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($project . '/templates/bundles/NowoInstanceSettingsBundle');

        try {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', $project);
            $container->setDefinition('twig.loader.native_filesystem', new Definition('stdClass'));
            $container->setAlias('twig.loader.native', 'twig.loader.alias');
            $container->setAlias('twig.loader.alias', 'twig.loader.native_filesystem');
            (new TwigPathsPass())->process($container);

            self::assertSame([
                ['prependPath', [$project . '/templates/bundles/NowoInstanceSettingsBundle', 'NowoInstanceSettingsBundle']],
                ['addPath', [$views, 'NowoInstanceSettingsBundle']],
            ], $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls());

            $plain = new ContainerBuilder();
            $plain->setParameter('kernel.project_dir', $project . '/missing');
            $plain->setDefinition('twig.loader.native', new Definition('stdClass'));
            (new TwigPathsPass())->process($plain);
            self::assertSame([['addPath', [$views, 'NowoInstanceSettingsBundle']]], $plain->getDefinition('twig.loader.native')->getMethodCalls());
        } finally {
            (new Filesystem())->remove($project);
        }
    }
}
