<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Runtime;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\RuntimeSettingPortsPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use Nowo\InstanceSettingsBundle\Runtime\InstanceSettingsRuntimeSource;
use Nowo\InstanceSettingsBundle\Runtime\InstanceSettingsRuntimeWriter;
use Nowo\InstanceSettingsBundle\Runtime\RuntimeSettingAdminGuard;
use Nowo\InstanceSettingsBundle\RuntimeEntity\RuntimeSettingPrevious;
use Nowo\InstanceSettingsBundle\RuntimeEntity\RuntimeSettingVersion;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriterInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Nowo\RuntimeSettingContract\Contract\Validator\IntRange;
use Nowo\RuntimeSettingContract\Contract\Validator\SingleLineString;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * 1.20: the admin validation of the registered runtime settings, the ports pass and the runtime entities.
 */
final class RuntimeSettingAdminGuardTest extends TestCase
{
    public function testRegisteredWritableSettingsPassTheirValidator(): void
    {
        $guard = new RuntimeSettingAdminGuard(new RuntimeSettingRegistry([
            new RuntimeSettingDefinition('chat.retention_days', RuntimeSettingType::Int, RuntimeSettingSource::DbThenEnv, validator: new IntRange(1, 1000)),
            new RuntimeSettingDefinition('chat.flag', RuntimeSettingType::Bool, RuntimeSettingSource::Db, validator: static fn (string|int|float|bool $v): bool => true),
            new RuntimeSettingDefinition('chat.ratio', RuntimeSettingType::Float, RuntimeSettingSource::Db, validator: static fn (string|int|float|bool $v): float => 0.5),
            new RuntimeSettingDefinition('chat.secret', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true, validator: new SingleLineString()),
            new RuntimeSettingDefinition('chat.env_only', RuntimeSettingType::Int, configured: 3),
            new RuntimeSettingDefinition('chat.list', RuntimeSettingType::String, RuntimeSettingSource::Db, validator: new SingleLineString()),
        ]));

        self::assertSame([], $guard->violations('chat', ['retention_days' => 45, 'flag' => false, 'ratio' => 0.5, 'other' => 'x', 'env_only' => 99999, 'secret' => null, 'list' => [1]]), 'a value that is no scalar is left to the field rules');
        self::assertSame(['retention_days' => ['must be between 1 and 1000']], $guard->violations('chat', ['retention_days' => 5000]));
        self::assertSame(['secret' => ['refused by the validator']], $guard->violations('chat', ['secret' => "two\nlines"]));
        self::assertSame([], $guard->violations('chat', ['retention_days' => '']));
    }

    public function testThePortsPassBindsBothPortsOrNeither(): void
    {
        $off = new ContainerBuilder();
        $off->setParameter(NowoInstanceSettingsExtension::RUNTIME_ENABLED_PARAMETER, false);
        (new RuntimeSettingPortsPass())->process($off);
        self::assertFalse($off->has(RuntimeValueSourceInterface::class));

        $on = $this->enabled();
        (new RuntimeSettingPortsPass())->process($on);
        self::assertSame(InstanceSettingsRuntimeSource::class, (string) $on->getAlias(RuntimeValueSourceInterface::class));
        self::assertSame(InstanceSettingsRuntimeWriter::class, (string) $on->getAlias(RuntimeSettingWriterInterface::class));
        self::assertFalse($on->hasDefinition(RuntimeSettingAdminGuard::class));

        $split = $this->enabled();
        $split->register('host.source', stdClass::class);
        $split->setAlias(RuntimeValueSourceInterface::class, 'host.source');
        (new RuntimeSettingPortsPass())->process($split);
        self::assertFalse($split->has(RuntimeSettingWriterInterface::class), 'never a source from one backend and a writer from another');

        $guarded = $this->enabled();
        $guarded->register(RuntimeSettingRegistry::class, stdClass::class);
        $guarded->setDefinition(InstanceSettings::class, new Definition(InstanceSettings::class, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]));
        (new RuntimeSettingPortsPass())->process($guarded);
        self::assertSame(RuntimeSettingAdminGuard::class, (string) $guarded->getDefinition(InstanceSettings::class)->getArgument(12));

        (new RuntimeSettingPortsPass())->process(new ContainerBuilder());
    }

    public function testRuntimeEntities(): void
    {
        $version = new RuntimeSettingVersion('chat', 'abc');
        self::assertSame(['chat', 'abc'], [$version->getSection(), $version->getToken()]);

        $expires  = new DateTimeImmutable('2026-11-16 13:00:00');
        $previous = (new RuntimeSettingPrevious('chat', 'webhook_secret', $expires))->setId(3)->setValue(5);
        $previous->setSecretValue('enc');
        self::assertSame([3, 'chat', 'webhook_secret', 5, 'enc', $expires], [$previous->getId(), $previous->getSection(), $previous->getName(), $previous->getValue(), $previous->getSecretValue(), $previous->getExpiresAt()]);
    }

    private function enabled(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoInstanceSettingsExtension::RUNTIME_ENABLED_PARAMETER, true);

        return $container;
    }
}
