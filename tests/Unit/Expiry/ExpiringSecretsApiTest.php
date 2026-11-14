<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Expiry;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\InstanceSettingsBundle\Command\CheckExpiringSecretsCommand;
use Nowo\InstanceSettingsBundle\DependencyInjection\Configuration;
use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use Nowo\InstanceSettingsBundle\Expiry\ExpiringSecrets;
use Nowo\InstanceSettingsBundle\Expiry\ExpiryDate;
use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryChecker;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\PortabilityEnvelope;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use Nowo\InstanceSettingsBundle\Section\SectionFallbackProviderInterface;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Seed\FallbackSeeder;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ExpiringFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySecretExpiryStore;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingDispatcher;
use Nowo\InstanceSettingsBundle\Tests\Support\SealerFactory;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function json_encode;
use function trim;

/**
 * 1.17: the API around the expiring secrets — field flag, registry gate, fallback seeding, export / import, configuration
 * and the command.
 */
final class ExpiringSecretsApiTest extends TestCase
{
    private InMemorySettingValueRepository $repository;

    private InMemorySecretExpiryStore $store;

    private InstanceSettings $settings;

    protected function setUp(): void
    {
        $this->repository = new InMemorySettingValueRepository();
        $this->store      = new InMemorySecretExpiryStore();
        $this->settings   = $this->settingsOn($this->repository, $this->store);
    }

    public function testTheFieldFlag(): void
    {
        $field = SettingField::secret('api_key', expires: true);
        self::assertTrue($field->expires);
        self::assertFalse(SettingField::secret('token')->expires, 'off by default');
        foreach ([$field->notPortable(), $field->withFormOptions(['attr' => []]), $field->withGroup('keys'), $field->withVisibleWhen('mode', ['x'])] as $copy) {
            self::assertTrue($copy->expires, 'copies keep the flag');
        }
        self::assertSame('api_key:expires_on', SettingField::expiryKey('api_key'));
        self::assertSame('api_key', SettingField::expiryField('api_key:expires_on'));
        self::assertNull(SettingField::expiryField('api_key'));
        self::assertNull(SettingField::expiryField('Bad Key:expires_on'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only a secret field can carry an expiry date');
        new SettingField('plain', expires: true);
    }

    public function testAnExpiryFlagPrependedAfterThisExtensionFailsClearly(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoInstanceSettingsExtension::EXPIRY_MAPPED_PARAMETER, false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the expiry table is not mapped');
        (new NowoInstanceSettingsExtension())->load([['web_ui' => ['enabled' => false], 'expiring_secrets' => ['enabled' => true]]], $container);
    }

    public function testTheRegistryFailsClosedWithoutTheFlag(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('expiring_secrets.enabled: true');
        (new SettingsSectionRegistry([new ExpiringFixtureSection()], expiryEnabled: false))->fields('providers');
    }

    public function testAFallbackCarriesTheDateOfItsSecret(): void
    {
        $fallback = SettingFallback::secret('api_key', 'sk-seeded', '2027-06-30');
        self::assertSame('2027-06-30', $fallback->expiresOn);
        self::assertSame(['field' => 'api_key', 'value' => '***', 'secret' => true, 'expiresOn' => '2027-06-30'], $fallback->jsonSerialize());
        self::assertSame(['field' => 'x', 'value' => 'y', 'secret' => false], SettingFallback::plain('x', 'y')->jsonSerialize(), 'unchanged without a date');

        foreach ([static fn (): SettingFallback => new SettingFallback('x', 'y', false, '2027-06-30'), static fn (): SettingFallback => SettingFallback::secret('x', 'y', '30/06/2027')] as $build) {
            try {
                $build();
                self::fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('expiry date', $e->getMessage());
            }
        }
    }

    public function testTheFallbackSeederStoresTheDateWithTheSecret(): void
    {
        $section = new class extends ExpiringFixtureSection implements SectionFallbackProviderInterface {
            public function getFallbacks(): iterable
            {
                yield SettingFallback::secret('api_key', 'sk-seeded', '2027-06-30');
                // A date on a field without `expires` is ignored.
                yield SettingFallback::secret('webhook_token', 'whk-seeded', '2027-06-30');
                yield SettingFallback::secret('backup_key', 'bk-seeded');
            }
        };
        $registry = new SettingsSectionRegistry([$section]);
        $settings = new InstanceSettings($this->repository, $registry, expiryStore: $this->store);

        $dry = (new FallbackSeeder($registry, $this->repository, $settings, expiryStore: $this->store))->seed(null, true);
        self::assertSame([], $this->store->records, 'a dry run writes no date');
        self::assertContains('providers.api_key', $dry->seeded);

        $report = (new FallbackSeeder($registry, $this->repository, $settings, clock: new MockClock('2026-11-14'), expiryStore: $this->store))->seed();
        self::assertContains('providers.api_key', $report->seeded);
        self::assertSame(['providers.api_key'], array_keys($this->store->records));
        self::assertSame('2027-06-30', $this->dateIn($this->store, 'providers.api_key'));

        // Insert-only: a second run neither re-seeds the secret nor rewrites its date.
        $settings->save('providers', ['api_key:expires_on' => '2028-01-01']);
        (new FallbackSeeder($registry, $this->repository, $settings, expiryStore: $this->store))->seed();
        self::assertSame('2028-01-01', $this->dateIn($this->store, 'providers.api_key'));
    }

    public function testExportCarriesTheDateInClearAndNeverTheSecret(): void
    {
        $this->settings->save('providers', ['api_key' => 'sk-export-SECRET', 'api_key:expires_on' => '2027-01-31', 'webhook_token' => 'whk-SECRET']);
        // A date left without its secret (written around the bundle) is not exported.
        $this->store->write('providers', ['backup_key' => new DateTimeImmutable('2027-02-01')], new DateTimeImmutable());

        $plain = $this->portability()->export();
        self::assertSame(PortabilityEnvelope::VERSION_WITH_SECRET_EXPIRY, $plain['version']);
        self::assertSame(['providers' => ['api_key' => '2027-01-31']], $plain['secret_expiry']);
        self::assertStringNotContainsString('SECRET', (string) json_encode($plain));

        $sealed = $this->portability()->export(null, true);
        self::assertSame(['providers' => ['api_key' => '2027-01-31']], $sealed['secret_expiry']);
        self::assertStringNotContainsString('sk-export-SECRET', (string) json_encode($sealed), 'secrets only sealed');

        $empty = new ConfigPortability(new SettingsSectionRegistry([new ExpiringFixtureSection()]), $this->settingsOn(new InMemorySettingValueRepository(), new InMemorySecretExpiryStore()), new InMemorySettingValueRepository());
        self::assertSame(PortabilityEnvelope::VERSION_WITHOUT_FILES, $empty->export()['version'], 'no date: the 1.16 envelope');
        self::assertArrayNotHasKey('secret_expiry', $empty->export());
    }

    public function testImportAppliesTheDateWithItsSecret(): void
    {
        $this->settings->save('providers', ['api_key' => 'sk-export-SECRET', 'api_key:expires_on' => '2027-01-31']);
        $payload = $this->portability()->export(null, true);

        $targetRepository = new InMemorySettingValueRepository();
        $targetStore      = new InMemorySecretExpiryStore();
        $target           = $this->portability($targetRepository, $targetStore);

        $dry = $target->import($payload, true);
        self::assertContains('providers.api_key:expires_on', $dry->changedKeys());
        self::assertSame([], $targetStore->records);

        $result = $target->import($payload);
        self::assertContains('providers.api_key', $result->changedKeys());
        self::assertContains('providers.api_key:expires_on', $result->changedKeys());
        self::assertSame('2027-01-31', $this->dateIn($targetStore, 'providers.api_key'));

        // A date alone applies to the stored secret; null removes it.
        $target->import(['schema' => 'nowo-instance-settings', 'version' => 3, 'secret_expiry' => ['providers' => ['api_key' => '2027-05-01']]]);
        self::assertSame('2027-05-01', $this->dateIn($targetStore, 'providers.api_key'));
        $target->import(['schema' => 'nowo-instance-settings', 'version' => 3, 'secret_expiry' => ['providers' => ['api_key' => null]]]);
        self::assertSame([], $targetStore->records);

        // A date for a field that has no secret (none stored, none imported) is skipped, not refused.
        $skipped = $target->import(['schema' => 'nowo-instance-settings', 'version' => 3, 'secret_expiry' => ['providers' => ['backup_key' => '2027-05-01']]]);
        self::assertSame([], $skipped->changedKeys());
        self::assertSame([], $targetStore->records);
    }

    public function testImportRefusesMalformedDates(): void
    {
        $cases = [
            'invalid_secret_expiry'                          => 'x',
            'unknown_section:nope'                           => ['nope' => []],
            'invalid_section:providers'                      => ['providers' => 'x'],
            'unknown_key:providers.webhook_token:expires_on' => ['providers' => ['webhook_token' => '2027-01-01']],
            'unknown_key:providers.missing:expires_on'       => ['providers' => ['missing' => '2027-01-01']],
            'invalid_value:providers.api_key:expires_on'     => ['providers' => ['api_key' => '2027-02-30']],
            'invalid_value:providers.api_key:expires_on '    => ['providers' => ['api_key' => 20270101]],
        ];
        foreach ($cases as $reason => $expiry) {
            try {
                $this->portability()->import(['schema' => 'nowo-instance-settings', 'version' => 3, 'secret_expiry' => $expiry]);
                self::fail('Expected ' . $reason);
            } catch (PortabilityException $e) {
                self::assertSame(trim($reason), $e->getMessage());
            }
        }

        $notPortable = new class extends ExpiringFixtureSection {
            public function getFields(): iterable
            {
                yield SettingField::secret('api_key', expires: true)->notPortable();
            }
        };
        $portability = new ConfigPortability(new SettingsSectionRegistry([$notPortable]), $this->settings, $this->repository);
        try {
            $portability->import(['schema' => 'nowo-instance-settings', 'version' => 3, 'secret_expiry' => ['providers' => ['api_key' => '2027-01-01']]]);
            self::fail('Expected not_portable');
        } catch (PortabilityException $e) {
            self::assertSame('not_portable:providers.api_key:expires_on', $e->getMessage());
        }

        try {
            $this->portability()->import(['schema' => 'nowo-instance-settings', 'version' => 3, 'secret_expiry' => ['providers' => ['api_key' => '2027-01-01']]], false, ['general']);
            self::fail('Expected forbidden_section');
        } catch (PortabilityException $e) {
            self::assertSame('forbidden_section:providers', $e->getMessage());
        }
    }

    public function testConfiguration(): void
    {
        $defaults = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertSame(['enabled' => false, 'thresholds' => [30, 7, 1, 0], 'warning' => true, 'warning_days' => 30, 'timezone' => null], $defaults['expiring_secrets']);

        $custom = (new Processor())->processConfiguration(new Configuration(), [['expiring_secrets' => ['enabled' => true, 'thresholds' => [14, 0], 'warning' => false, 'warning_days' => 7, 'timezone' => 'Europe/Madrid']]]);
        self::assertSame(['enabled' => true, 'thresholds' => [14, 0], 'warning' => false, 'warning_days' => 7, 'timezone' => 'Europe/Madrid'], $custom['expiring_secrets']);

        foreach ([['thresholds' => []], ['thresholds' => [7, 7]], ['thresholds' => [-1]], ['timezone' => 'Mars/Olympus'], ['timezone' => 3], ['warning_days' => 4000]] as $invalid) {
            try {
                (new Processor())->processConfiguration(new Configuration(), [['expiring_secrets' => $invalid]]);
                self::fail('Expected InvalidConfigurationException for ' . json_encode($invalid));
            } catch (InvalidConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheCommand(): void
    {
        $clock = new MockClock('2026-12-31 08:00:00');
        $this->settings->save('providers', ['api_key' => 'sk-cmd-SECRET', 'api_key:expires_on' => '2026-12-31']);
        $secrets = new ExpiringSecrets(new SettingsSectionRegistry([new ExpiringFixtureSection()]), $this->store, $this->settings, $clock, 'UTC');

        $dry = new CommandTester(new CheckExpiringSecretsCommand(new SecretExpiryChecker($secrets, $this->store, clock: $clock)));
        self::assertSame(Command::SUCCESS, $dry->execute(['--dry-run' => true]));
        self::assertStringContainsString('providers.api_key', $dry->getDisplay());
        self::assertStringContainsString('Due: 1 expiry reminder(s).', $dry->getDisplay());

        $failing = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                throw new RuntimeException('down');
            }
        };
        $failed = new CommandTester(new CheckExpiringSecretsCommand(new SecretExpiryChecker($secrets, $this->store, dispatcher: $failing, clock: $clock)));
        self::assertSame(Command::FAILURE, $failed->execute([]));
        self::assertStringContainsString('providers.api_key', $failed->getDisplay());

        $dispatcher = new RecordingDispatcher();
        $tester     = new CommandTester(new CheckExpiringSecretsCommand(new SecretExpiryChecker($secrets, $this->store, dispatcher: $dispatcher, clock: $clock)));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Announced 1 expiry reminder(s).', $tester->getDisplay());
        self::assertStringContainsString('2026-12-31', $tester->getDisplay());
        self::assertStringNotContainsString('sk-cmd-SECRET', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Announced 0 expiry reminder(s).', $tester->getDisplay());
        self::assertCount(1, $dispatcher->events);
    }

    private function dateIn(InMemorySecretExpiryStore $store, string $key): ?string
    {
        $record = $store->findAll();
        foreach ($record as $entry) {
            if ($entry->section . '.' . $entry->field === $key) {
                return ExpiryDate::format($entry->expiresOn);
            }
        }

        return null;
    }

    private function settingsOn(InMemorySettingValueRepository $repository, InMemorySecretExpiryStore $store): InstanceSettings
    {
        return new InstanceSettings($repository, new SettingsSectionRegistry([new ExpiringFixtureSection()]), new ValueNormalizer(), expiryStore: $store);
    }

    private function portability(?InMemorySettingValueRepository $repository = null, ?InMemorySecretExpiryStore $store = null): ConfigPortability
    {
        $repository ??= $this->repository;
        $store ??= $this->store;
        $settings = $repository === $this->repository ? $this->settings : $this->settingsOn($repository, $store);

        return new ConfigPortability(new SettingsSectionRegistry([new ExpiringFixtureSection()]), $settings, $repository, SealerFactory::create(), expiryStore: $store);
    }
}
