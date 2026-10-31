<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Portability;

use LogicException;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use Nowo\InstanceSettingsBundle\Portability\PortabilityProviderInterface;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\AppearancePortabilityProvider;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\SealerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Validator\Validation;

final class ConfigPortabilityTest extends TestCase
{
    private InMemorySettingValueRepository $repository;

    private InstanceSettings $settings;

    private AppearancePortabilityProvider $provider;

    protected function setUp(): void
    {
        $this->repository = new InMemorySettingValueRepository();
        $this->settings   = new InstanceSettings($this->repository, $this->registry(), new ValueNormalizer(Validation::createValidator()));
        $this->provider   = new AppearancePortabilityProvider();
    }

    public function testExportPlainValuesOnly(): void
    {
        $this->settings->save('mailer', ['from' => 'ops@example.test', 'dsn' => 'smtp://REDACTED-TEST']);
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = $message . ' ' . json_encode($context);
            }
        };

        $payload = $this->portability(logger: $logger)->export();

        self::assertSame('nowo-instance-settings', $payload['schema']);
        self::assertSame(1, $payload['version']);
        self::assertSame('2026-10-31T10:00:00+00:00', $payload['exported_at']);
        self::assertSame(['from' => 'ops@example.test', 'from_name' => 'Example', 'test_recipient' => null, 'enabled' => false], $payload['settings']['mailer']);
        self::assertArrayNotHasKey('maintenance_lock', $payload['settings']['general'], 'not portable');
        self::assertArrayNotHasKey('secrets', $payload);
        self::assertSame(['appearance' => ['color' => '#112233']], $payload['providers']);
        self::assertStringNotContainsString('REDACTED', (string) json_encode($payload));
        self::assertStringContainsString('mailer,general,appearance', $logger->messages[0]);
    }

    public function testExportFilterAndSealedSecrets(): void
    {
        $this->settings->save('mailer', ['dsn' => 'smtp://REDACTED-TEST']);
        $portability = $this->portability();

        $payload = $portability->export(['mailer'], true);
        self::assertSame(['mailer'], array_keys($payload['settings']));
        self::assertArrayNotHasKey('providers', $payload);
        self::assertTrue($payload['secrets']['sealed']);
        self::assertSame('doctrine_encrypt:default', $payload['secrets']['algorithm']);
        self::assertStringStartsWith('enc:v1:', $payload['secrets']['values']['mailer']['dsn']);
        self::assertStringNotContainsString('REDACTED', (string) json_encode($payload));

        self::assertSame(['appearance'], array_keys($portability->export(['appearance'])['providers']));
        self::assertSame([], $portability->export(['appearance'])['settings']);
    }

    public function testExportRefusals(): void
    {
        try {
            $this->portability()->export(['nope']);
            self::fail('Expected unknown_section');
        } catch (PortabilityException $e) {
            self::assertSame('unknown_section:nope', $e->getMessage());
        }

        $disabled = $this->portability(sealed: false);
        self::assertFalse($disabled->supportsSealedSecrets());
        $this->expectExceptionMessage('sealed_secrets_disabled');
        $disabled->export(null, true);
    }

    public function testRoundTripIncludingSecrets(): void
    {
        $this->settings->save('mailer', ['from' => 'ops@example.test', 'dsn' => 'smtp://REDACTED-TEST']);
        $this->settings->save('general', ['page_size' => 50, 'maintenance_lock' => true]);
        $payload = $this->portability()->export(null, true);

        $target         = new InMemorySettingValueRepository();
        $targetSettings = new InstanceSettings($target, $this->registry(), new ValueNormalizer(Validation::createValidator()));
        $provider       = new AppearancePortabilityProvider();
        $provider->data = ['color' => '#000000'];
        $portability    = new ConfigPortability($this->registry(), $targetSettings, $target, SealerFactory::create(), [$provider]);

        $dry = $portability->import($payload, true);
        self::assertTrue($dry->dryRun);
        self::assertContains('mailer.dsn', $dry->changedKeys());
        self::assertContains('appearance.color', $dry->changedKeys());
        self::assertSame(0, $target->flushes);

        $result = $portability->import($payload);
        self::assertFalse($result->dryRun);
        self::assertSame(1, $target->transactions);
        self::assertSame('ops@example.test', $targetSettings->get('mailer.from'));
        self::assertSame('smtp://REDACTED-TEST', $targetSettings->getSecret('mailer.dsn'));
        self::assertSame(50, $targetSettings->getInt('general.page_size'));
        self::assertFalse($targetSettings->getBool('general.maintenance_lock'), 'not portable: never imported');
        self::assertSame(['color' => '#112233'], $provider->data);
        self::assertContains('mailer.dsn', $result->changedKeys());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusedPayloads(): iterable
    {
        $base = ['schema' => 'nowo-instance-settings', 'version' => 1];
        yield 'schema' => [['schema' => 'other', 'version' => 1], 'invalid_schema'];
        yield 'empty' => [$base + ['settings' => []], 'empty_payload'];
        yield 'settings type' => [$base + ['settings' => 'x'], 'invalid_settings'];
        yield 'unknown section' => [$base + ['settings' => ['nope' => []]], 'unknown_section:nope'];
        yield 'section type' => [$base + ['settings' => ['mailer' => 'x']], 'invalid_section:mailer'];
        yield 'unknown field' => [$base + ['settings' => ['mailer' => ['nope' => 1]]], 'unknown_key:mailer.nope'];
        yield 'secret in clear' => [$base + ['settings' => ['mailer' => ['dsn' => 'smtp://x']]], 'secret_in_clear:mailer.dsn'];
        yield 'not portable' => [$base + ['settings' => ['general' => ['maintenance_lock' => true]]], 'not_portable:general.maintenance_lock'];
        yield 'invalid value' => [$base + ['settings' => ['general' => ['page_size' => 1000]]], 'invalid_value:general.page_size'];
        yield 'secrets shape' => [$base + ['secrets' => ['sealed' => false, 'values' => []]], 'invalid_secrets'];
        yield 'secrets unknown section' => [$base + ['secrets' => ['sealed' => true, 'values' => ['nope' => []]]], 'unknown_section:nope'];
        yield 'secrets section type' => [$base + ['secrets' => ['sealed' => true, 'values' => ['mailer' => 'x']]], 'invalid_section:mailer'];
        yield 'secrets plain field' => [$base + ['secrets' => ['sealed' => true, 'values' => ['mailer' => ['from' => 'x']]]], 'unknown_key:mailer.from'];
        yield 'secrets value type' => [$base + ['secrets' => ['sealed' => true, 'values' => ['mailer' => ['dsn' => 5]]]], 'invalid_sealed_secret:mailer.dsn'];
        yield 'secrets not sealed' => [$base + ['secrets' => ['sealed' => true, 'values' => ['mailer' => ['dsn' => 'smtp://x']]]], 'invalid_sealed_secret:mailer.dsn'];
        yield 'providers type' => [$base + ['providers' => 'x'], 'invalid_providers'];
        yield 'unknown provider' => [$base + ['providers' => ['nope' => []]], 'unknown_section:nope'];
        yield 'provider data type' => [$base + ['providers' => ['appearance' => 'x']], 'invalid_section:appearance'];
        yield 'provider credential' => [$base + ['providers' => ['appearance' => ['color' => '#000000', 'nested' => ['api_token' => 'x']]]], 'forbidden_key:appearance.nested.api_token'];
        yield 'provider validation' => [$base + ['providers' => ['appearance' => ['color' => 'red']]], 'invalid_value:appearance.color'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('refusedPayloads')]
    public function testImportRefusals(array $payload, string $reason): void
    {
        try {
            $this->portability()->import($payload);
            self::fail('Expected ' . $reason);
        } catch (PortabilityException $e) {
            self::assertSame($reason, $e->getMessage());
        }

        self::assertSame(0, $this->repository->flushes);
    }

    public function testSecretsFromAnotherInstanceOrInvalidAreRefused(): void
    {
        $other   = SealerFactory::create('k2');
        $payload = ['schema' => 'nowo-instance-settings', 'version' => 1, 'secrets' => ['sealed' => true, 'values' => ['mailer' => ['dsn' => $other->seal('smtp://x')]]]];

        try {
            $this->portability()->import($payload);
            self::fail('Expected failed_unseal_secret');
        } catch (PortabilityException $e) {
            self::assertSame('failed_unseal_secret:mailer.dsn', $e->getMessage());
        }

        $sealer                                        = SealerFactory::create();
        $payload['secrets']['values']['mailer']['dsn'] = $sealer->seal('   ');
        $section                                       = new class extends MailerFixtureSection {
            public function getFields(): iterable
            {
                yield SettingField::secret('dsn', required: true);
                yield SettingField::secret('locked')->notPortable();
            }
        };
        $registry    = new SettingsSectionRegistry([$section]);
        $portability = new ConfigPortability($registry, new InstanceSettings($this->repository, $registry), $this->repository, $sealer);

        try {
            $portability->import($payload);
            self::fail('Expected invalid_value');
        } catch (PortabilityException $e) {
            self::assertSame('invalid_value:mailer.dsn', $e->getMessage());
        }

        try {
            $portability->import(['schema' => 'nowo-instance-settings', 'version' => 1, 'secrets' => ['sealed' => true, 'values' => ['mailer' => ['locked' => $sealer->seal('x')]]]]);
            self::fail('Expected not_portable');
        } catch (PortabilityException $e) {
            self::assertSame('not_portable:mailer.locked', $e->getMessage());
        }
    }

    public function testSealedSecretsDisabledRefusesImport(): void
    {
        $this->expectExceptionMessage('sealed_secrets_disabled');
        $this->portability(sealed: false)->import(['schema' => 'nowo-instance-settings', 'version' => 1, 'secrets' => ['sealed' => true, 'values' => []]]);
    }

    public function testFailingProviderRollsBack(): void
    {
        $failing = new class implements PortabilityProviderInterface {
            public function getName(): string
            {
                return 'failing';
            }

            public function export(): array
            {
                return [];
            }

            public function validate(array $data): void
            {
            }

            public function import(array $data): array
            {
                throw new RuntimeException('boom');
            }
        };
        $portability = new ConfigPortability($this->registry(), $this->settings, $this->repository, null, [$failing], 'custom-schema');
        self::assertSame('custom-schema', $portability->schema());

        try {
            $portability->import(['schema' => 'custom-schema', 'version' => 1, 'settings' => ['general' => ['page_size' => 7]], 'providers' => ['failing' => ['x' => 1]]]);
            self::fail('Expected RuntimeException');
        } catch (RuntimeException) {
            self::assertSame([], $this->repository->rows);
        }
    }

    public function testInvalidProviderName(): void
    {
        $provider = new class extends AppearancePortabilityProvider {
            public function getName(): string
            {
                return 'mailer';
            }
        };

        $this->expectException(LogicException::class);
        (new ConfigPortability($this->registry(), $this->settings, $this->repository, null, [$provider]))->export();
    }

    public function testCredentialKeys(): void
    {
        foreach (['password', 'Client_Secret', 'smtp_password', 'api_token', 'private_key', 'mailer_dsn'] as $key) {
            self::assertTrue(ConfigPortability::isCredentialKey($key), $key);
        }
        foreach (['color', 'token_required', 'keyboard'] as $key) {
            self::assertFalse(ConfigPortability::isCredentialKey($key), $key);
        }
    }

    public function testCredentialShapedFlagsAreAllowed(): void
    {
        $provider = new class extends AppearancePortabilityProvider {
            public function validate(array $data): void
            {
            }
        };
        $result = (new ConfigPortability($this->registry(), $this->settings, $this->repository, null, [$provider]))->import([
            'schema'    => 'nowo-instance-settings',
            'version'   => 1,
            'providers' => ['appearance' => ['color' => '#000000', 'api_token' => true, 'list' => [1, 2]]],
        ]);
        self::assertSame(['appearance.color', 'appearance.api_token', 'appearance.list'], $result->changedKeys());
    }

    private function registry(): SettingsSectionRegistry
    {
        return new SettingsSectionRegistry([new MailerFixtureSection(), new GeneralFixtureSection()]);
    }

    private function portability(bool $sealed = true, ?AbstractLogger $logger = null): ConfigPortability
    {
        return new ConfigPortability(
            $this->registry(),
            $this->settings,
            $this->repository,
            SealerFactory::create(),
            [$this->provider],
            allowSealedSecrets: $sealed,
            normalizer: new ValueNormalizer(Validation::createValidator()),
            clock: new MockClock('2026-10-31T10:00:00+00:00'),
            logger: $logger,
        );
    }
}
