<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Portability;

use LogicException;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Secret\SecretSealerInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\InstanceSettingsBundle\Settings\ValueNormalizer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;

use function array_intersect;
use function array_key_exists;
use function array_keys;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtolower;

/**
 * Export / import of the instance configuration (settings sections + tagged providers) in a versioned JSON envelope
 * ({@see PortabilityEnvelope}).
 *
 * - Export: effective values of every portable plain field; secrets only when asked for and only sealed with the
 *   instance key ({@see SecretSealerInterface}); provider data.
 * - Import: all-or-nothing. The whole payload is validated first (schema, version, known sections / fields,
 *   portable fields only, value types and constraints, sealed secrets that decrypt with this instance key,
 *   credential-shaped keys refused in provider data, provider validation), then applied in one transaction.
 */
final readonly class ConfigPortability
{
    /**
     * Credential words matched anywhere in a normalized key (camelCase split, `-` / `.` / spaces as `_`, lower case,
     * underscores removed): `clientSecret`, `smtp-password`, `accessToken`, `x.api_key` all match.
     */
    public const CREDENTIAL_WORDS = ['password', 'passwd', 'passphrase', 'secret', 'token', 'bearer', 'credential', 'apikey', 'privatekey', 'authorization', 'cookie', 'signature'];

    /** Short credential tokens matched as a whole `_`-separated part of the normalized key (`pwd`, `api_key`, `smtp_dsn`). */
    public const CREDENTIAL_TOKENS = ['pwd', 'pass', 'key', 'dsn', 'auth', 'otp', 'pin', 'salt', 'session'];

    /** Allowed provider names. */
    private const PROVIDER_NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param iterable<PortabilityProviderInterface> $providers tagged `nowo_instance_settings.portability_provider`
     * @param string $schema envelope `schema` value
     * @param bool $allowSealedSecrets whether sealed secrets may be exported / imported
     */
    public function __construct(
        private SettingsSectionRegistry $registry,
        private InstanceSettingsInterface $settings,
        private SettingValueRepositoryInterface $repository,
        private ?SecretSealerInterface $sealer = null,
        private iterable $providers = [],
        private string $schema = PortabilityEnvelope::DEFAULT_SCHEMA,
        private bool $allowSealedSecrets = true,
        private ValueNormalizer $normalizer = new ValueNormalizer(),
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether sealed secret export / import is available (enabled and a sealer is wired).
     */
    public function supportsSealedSecrets(): bool
    {
        return $this->allowSealedSecrets && $this->sealer instanceof SecretSealerInterface;
    }

    /**
     * Names of the registered portability providers.
     *
     * @return list<string>
     */
    public function providerNames(): array
    {
        return array_keys($this->providers());
    }

    /**
     * Configured envelope schema.
     */
    public function schema(): string
    {
        return $this->schema;
    }

    /**
     * Builds the export envelope.
     *
     * @param list<string>|null $only section / provider names to include (null = everything)
     * @param bool $includeSecrets add the stored secrets, sealed (requires {@see supportsSealedSecrets()})
     *
     * @throws PortabilityException `unknown_section:<name>`, `sealed_secrets_disabled`
     *
     * @return array<string, mixed>
     */
    public function export(?array $only = null, bool $includeSecrets = false): array
    {
        if ($includeSecrets && !$this->supportsSealedSecrets()) {
            throw new PortabilityException('sealed_secrets_disabled');
        }

        $providers = $this->providers();
        foreach ($only ?? [] as $name) {
            if (!$this->registry->has($name) && !array_key_exists($name, $providers)) {
                throw new PortabilityException('unknown_section:' . $name);
            }
        }

        $settings = [];
        $secrets  = [];
        foreach ($this->registry->names() as $section) {
            if ($only !== null && !in_array($section, $only, true)) {
                continue;
            }
            foreach ($this->registry->fields($section) as $name => $field) {
                if (!$field->portable) {
                    continue;
                }
                if (!$field->secret) {
                    $settings[$section][$name] = $this->settings->get($section . '.' . $name);

                    continue;
                }
                if (!$includeSecrets || !$this->sealer instanceof SecretSealerInterface) {
                    continue;
                }
                $secret = $this->settings->getSecret($section . '.' . $name);
                if ($secret !== null) {
                    $secrets[$section][$name] = $this->sealer->seal($secret, $this->context($section, $name));
                }
            }
        }

        $payload             = PortabilityEnvelope::header($this->schema, $this->clock?->now() ?? (new NativeClock())->now());
        $payload['settings'] = $settings;
        if ($includeSecrets && $this->sealer instanceof SecretSealerInterface) {
            $payload['secrets'] = ['sealed' => true, 'algorithm' => $this->sealer->algorithm(), 'values' => $secrets];
        }

        $exported = [];
        foreach ($providers as $name => $provider) {
            if ($only === null || in_array($name, $only, true)) {
                $data = $provider->export();
                // Providers must never export secrets: refuse the whole export instead of leaking one.
                $this->assertNoCredentials($data, $name);
                $exported[$name] = $data;
            }
        }
        if ($exported !== []) {
            $payload['providers'] = $exported;
        }

        $this->logger?->info('Instance configuration exported.', [
            'bundle'   => 'NowoInstanceSettingsBundle',
            'sections' => implode(',', [...array_keys($settings), ...array_keys($exported)]),
            'secrets'  => $includeSecrets,
        ]);

        return $payload;
    }

    /**
     * Validates the whole payload, then applies it in one transaction (or only validates when `$dryRun`).
     *
     * @param array<string, mixed> $payload decoded envelope
     * @param list<string>|null $allowedSections sections the caller may write (null = every section, e.g. the CLI);
     *                                           a payload touching another section is refused as a whole
     *
     * @throws PortabilityException with a reason code; nothing is written then
     */
    public function import(array $payload, bool $dryRun = false, ?array $allowedSections = null): ImportResult
    {
        PortabilityEnvelope::assertValid($payload, $this->schema);

        $settings = $this->validateSettings($payload['settings'] ?? []);
        $secrets  = $this->validateSecrets($payload['secrets'] ?? null);
        if ($allowedSections !== null) {
            foreach ([...array_keys($settings), ...array_keys($secrets)] as $section) {
                if (!in_array($section, $allowedSections, true)) {
                    throw new PortabilityException('forbidden_section:' . $section);
                }
            }
        }
        $providers = $this->validateProviders($payload['providers'] ?? []);

        if ($settings === [] && $secrets === [] && $providers === []) {
            throw new PortabilityException('empty_payload');
        }

        if ($dryRun) {
            $changes = [];
            foreach ([$settings, $secrets] as $group) {
                foreach ($group as $section => $values) {
                    foreach ($values as $name => $value) {
                        $changes[$section][] = $name;
                    }
                }
            }
            foreach ($providers as $name => $data) {
                $changes[$name] = array_keys($data);
            }

            return new ImportResult($changes, true);
        }

        // @igor-ignore - The closure runs synchronously inside the transaction and is not stored by the repository.
        $changes = $this->repository->transactional(function () use ($settings, $secrets, $providers): array {
            $changes  = [];
            $sections = $settings;
            foreach ($secrets as $section => $values) {
                $sections[$section] = ($sections[$section] ?? []) + $values;
            }
            foreach ($sections as $section => $values) {
                $changes[$section] = $this->settings->save($section, $values, SettingsChangedEvent::ORIGIN_IMPORT);
            }
            $providerMap = $this->providers();
            foreach ($providers as $name => $data) {
                $changes[$name] = $providerMap[$name]->import($data);
            }

            return $changes;
        });

        $result = new ImportResult($changes);
        $this->logger?->info('Instance configuration imported.', [
            'bundle'  => 'NowoInstanceSettingsBundle',
            'changed' => implode(',', $result->changedKeys()),
        ]);

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>> section => field => normalized value
     */
    private function validateSettings(mixed $settings): array
    {
        if (!is_array($settings)) {
            throw new PortabilityException('invalid_settings');
        }

        $valid = [];
        foreach ($settings as $section => $values) {
            $section = (string) $section;
            if (!$this->registry->has($section)) {
                throw new PortabilityException('unknown_section:' . $section);
            }
            if (!is_array($values)) {
                throw new PortabilityException('invalid_section:' . $section);
            }
            $fields = $this->registry->fields($section);
            foreach ($values as $name => $value) {
                $key   = $section . '.' . $name;
                $field = $fields[(string) $name] ?? null;
                if ($field === null) {
                    throw new PortabilityException('unknown_key:' . $key);
                }
                if ($field->secret) {
                    // Secrets only travel sealed, under `secrets`.
                    throw new PortabilityException('secret_in_clear:' . $key);
                }
                if (!$field->portable) {
                    throw new PortabilityException('not_portable:' . $key);
                }
                [$clean, $messages] = $this->normalizer->normalize($field, $value);
                if ($messages !== []) {
                    throw new PortabilityException('invalid_value:' . $key);
                }
                $valid[$section][$field->name] = $clean;
            }
        }

        return $valid;
    }

    /**
     * @return array<string, array<string, string>> section => field => plaintext secret
     */
    private function validateSecrets(mixed $secrets): array
    {
        if ($secrets === null) {
            return [];
        }
        if (!$this->supportsSealedSecrets() || !$this->sealer instanceof SecretSealerInterface) {
            throw new PortabilityException('sealed_secrets_disabled');
        }
        if (!is_array($secrets) || ($secrets['sealed'] ?? null) !== true || !is_array($secrets['values'] ?? null)) {
            throw new PortabilityException('invalid_secrets');
        }

        $valid = [];
        foreach ($secrets['values'] as $section => $values) {
            $section = (string) $section;
            if (!$this->registry->has($section)) {
                throw new PortabilityException('unknown_section:' . $section);
            }
            if (!is_array($values)) {
                throw new PortabilityException('invalid_section:' . $section);
            }
            $fields = $this->registry->fields($section);
            foreach ($values as $name => $sealed) {
                $key   = $section . '.' . $name;
                $field = $fields[(string) $name] ?? null;
                if ($field === null || !$field->secret) {
                    throw new PortabilityException('unknown_key:' . $key);
                }
                if (!$field->portable) {
                    throw new PortabilityException('not_portable:' . $key);
                }
                if (!is_string($sealed)) {
                    throw new PortabilityException('invalid_sealed_secret:' . $key);
                }
                try {
                    $plain = $this->sealer->unseal($sealed, $this->context($section, $field->name));
                } catch (PortabilityException $e) {
                    throw new PortabilityException($e->reason() . ':' . $key, $e);
                }
                [, $messages] = $this->normalizer->normalize($field, $plain);
                if ($messages !== []) {
                    throw new PortabilityException('invalid_value:' . $key);
                }
                $valid[$section][$field->name] = $plain;
            }
        }

        return $valid;
    }

    /**
     * @return array<string, array<string, mixed>> provider name => data
     */
    private function validateProviders(mixed $data): array
    {
        if (!is_array($data)) {
            throw new PortabilityException('invalid_providers');
        }

        $providers = $this->providers();
        $valid     = [];
        foreach ($data as $name => $values) {
            $name = (string) $name;
            if (!array_key_exists($name, $providers)) {
                throw new PortabilityException('unknown_section:' . $name);
            }
            if (!is_array($values)) {
                throw new PortabilityException('invalid_section:' . $name);
            }
            $this->assertNoCredentials($values, $name);
            /* @var array<string, mixed> $values */
            $providers[$name]->validate($values);
            $valid[$name] = $values;
        }

        return $valid;
    }

    /**
     * Refuses credential-shaped keys holding a string or a structure (booleans / numbers such as `token_required` or
     * `*_configured` stay allowed).
     *
     * @param array<mixed> $node
     */
    private function assertNoCredentials(array $node, string $path): void
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && (is_string($value) || is_array($value)) && self::isCredentialKey($key)) {
                throw new PortabilityException('forbidden_key:' . $path . '.' . $key);
            }
            if (is_array($value)) {
                $this->assertNoCredentials($value, $path . '.' . $key);
            }
        }
    }

    /**
     * Whether a key name looks like a credential (see {@see self::CREDENTIAL_WORDS} / {@see self::CREDENTIAL_TOKENS}).
     */
    public static function isCredentialKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace(['/([a-z0-9])([A-Z])/', '/[^A-Za-z0-9]+/'], ['$1_$2', '_'], $key));
        $compact    = str_replace('_', '', $normalized);
        foreach (self::CREDENTIAL_WORDS as $word) {
            if (str_contains($compact, $word)) {
                return true;
            }
        }

        return array_intersect(explode('_', $normalized), self::CREDENTIAL_TOKENS) !== [];
    }

    /**
     * Context a secret is sealed for: the export schema and its `<section>.<field>` key.
     */
    private function context(string $section, string $field): string
    {
        return $this->schema . '|' . $section . '.' . $field;
    }

    /**
     * @return array<string, PortabilityProviderInterface>
     */
    private function providers(): array
    {
        $map = [];
        foreach ($this->providers as $provider) {
            $name = $provider->getName();
            if (preg_match(self::PROVIDER_NAME_PATTERN, $name) !== 1 || array_key_exists($name, $map) || $this->registry->has($name)) {
                throw new LogicException(sprintf('Invalid or duplicate instance settings portability provider name "%s" (%s): lower snake case, unique, not a settings section name.', $name, $provider::class));
            }
            $map[$name] = $provider;
        }

        return $map;
    }
}
