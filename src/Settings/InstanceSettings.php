<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Settings;

use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use WeakReference;

use function implode;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * Default {@see InstanceSettingsInterface}: one query per request for every row, memoized for the current main request
 * only (a new main request always reloads, even when `kernel.reset` is not called between worker requests) and cleared
 * by {@see reset()} and after every save.
 */
final class InstanceSettings implements InstanceSettingsInterface, ResetInterface
{
    /** @var array<string, array<string, SettingValue>>|null section => field => row */
    private ?array $rows = null;

    /** @var WeakReference<Request>|null main request the memo belongs to (null outside HTTP) */
    private ?WeakReference $rowsRequest = null;

    /**
     * @param SettingValueRepositoryInterface $repository storage
     * @param SettingsSectionRegistry $registry registered sections
     * @param ValueNormalizer $normalizer value normalization and validation
     * @param EventDispatcherInterface|null $dispatcher receives {@see SettingsChangedEvent}
     * @param RequestStack|null $requestStack binds the memo to the main request
     * @param ClockInterface|null $clock `updated_at` source
     * @param LoggerInterface|null $logger logs section + field names of each save (never values)
     */
    public function __construct(
        private readonly SettingValueRepositoryInterface $repository,
        private readonly SettingsSectionRegistry $registry,
        private readonly ValueNormalizer $normalizer = new ValueNormalizer(),
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Effective plain value (stored, else default).
     */
    public function get(string $key): bool|float|int|string|null
    {
        [$section, $name, $field] = $this->registry->resolve($key);
        if ($field->secret) {
            throw new LogicException(sprintf('Instance setting "%s" is a secret: read it with getSecret().', $key));
        }

        return $this->effective($section, $name, $field);
    }

    /**
     * String value; throws when the field is not string-typed.
     */
    public function getString(string $key): ?string
    {
        $value = $this->get($key);
        if ($value !== null && !is_string($value)) {
            throw new LogicException(sprintf('Instance setting "%s" is not a string.', $key));
        }

        return $value;
    }

    /**
     * Int value; throws when the field is not int-typed.
     */
    public function getInt(string $key): ?int
    {
        $value = $this->get($key);
        if ($value !== null && !is_int($value)) {
            throw new LogicException(sprintf('Instance setting "%s" is not an int.', $key));
        }

        return $value;
    }

    /**
     * Float value (ints widen); throws for other types.
     */
    public function getFloat(string $key): ?float
    {
        $value = $this->get($key);
        if ($value !== null && !is_float($value) && !is_int($value)) {
            throw new LogicException(sprintf('Instance setting "%s" is not a number.', $key));
        }

        return $value === null ? null : (float) $value;
    }

    /**
     * Bool value (null = false); throws when the field is not a bool.
     */
    public function getBool(string $key): bool
    {
        [, , $field] = $this->registry->resolve($key);
        if ($field->type !== SettingType::Bool) {
            throw new LogicException(sprintf('Instance setting "%s" is not a bool.', $key));
        }

        return $this->get($key) === true;
    }

    /**
     * Decrypted secret or null.
     */
    public function getSecret(string $key): ?string
    {
        [$section, $name, $field] = $this->registry->resolve($key);
        if (!$field->secret) {
            throw new LogicException(sprintf('Instance setting "%s" is not a secret: read it with get().', $key));
        }

        $secret = ($this->rows()[$section][$name] ?? null)?->getSecretValue();

        return $secret === null || $secret === '' ? null : $secret;
    }

    /**
     * Whether a secret is stored.
     */
    public function hasSecret(string $key): bool
    {
        return $this->getSecret($key) !== null;
    }

    /**
     * Whether a row is stored for the field.
     */
    public function isOverridden(string $key): bool
    {
        [$section, $name] = $this->registry->resolve($key);

        return isset($this->rows()[$section][$name]);
    }

    /**
     * Effective plain values of a section (no secrets).
     */
    public function getSection(string $section): array
    {
        $values = [];
        foreach ($this->registry->fields($section) as $name => $field) {
            if (!$field->secret) {
                $values[$name] = $this->effective($section, $name, $field);
            }
        }

        return $values;
    }

    /**
     * Validates every value first, then upserts / removes the changed rows with one flush.
     */
    public function save(string $section, array $values, string $origin = SettingsChangedEvent::ORIGIN_API): array
    {
        $fields     = $this->registry->fields($section);
        $normalized = [];
        $violations = [];
        foreach ($values as $name => $value) {
            $field              = $this->registry->field($section, (string) $name);
            [$clean, $messages] = $this->normalizer->normalize($field, $value);
            if ($messages !== []) {
                $violations[$field->name] = $messages;

                continue;
            }
            $normalized[$field->name] = $clean;
        }
        if ($violations !== []) {
            throw new InvalidSettingValueException($section, $violations);
        }

        $existing = [];
        foreach ($this->repository->findBySection($section) as $row) {
            $existing[$row->getName()] = $row;
        }

        $now     = $this->clock?->now() ?? (new NativeClock())->now();
        $save    = [];
        $remove  = [];
        $changed = [];
        $secrets = [];
        foreach ($normalized as $name => $value) {
            $field = $fields[$name];
            $row   = $existing[$name] ?? null;
            if ($value === null) {
                if ($row !== null) {
                    $remove[]  = $row;
                    $changed[] = $name;
                }

                continue;
            }

            if ($field->secret) {
                if ($row !== null && $row->getSecretValue() === $value) {
                    continue;
                }
                $save[] = ($row ?? new SettingValue($section, $name, $now))->storeSecret((string) $value, $now);
            } else {
                if ($row !== null && $row->getSecretValue() === null && $row->getValue() === $value) {
                    continue;
                }
                $save[] = ($row ?? new SettingValue($section, $name, $now))->storeValue($value, $now);
            }
            $changed[] = $name;
        }

        foreach ($changed as $name) {
            if ($fields[$name]->secret) {
                $secrets[] = $name;
            }
        }

        if ($changed === []) {
            return [];
        }

        $this->repository->apply($save, $remove);
        $this->reset();

        $this->logger?->info('Instance settings saved.', [
            'bundle'  => 'NowoInstanceSettingsBundle',
            'section' => $section,
            'fields'  => implode(',', $changed),
            'origin'  => $origin,
        ]);
        $this->dispatcher?->dispatch(new SettingsChangedEvent($section, $changed, $secrets, $origin));

        return $changed;
    }

    /**
     * Clears the request memo (kernel.reset and after saves).
     */
    public function reset(): void
    {
        // Reset every memoized property here (not only through a helper) so the worker-state audit can see it.
        $this->rows        = null;
        $this->rowsRequest = null;
    }

    private function effective(string $section, string $name, SettingField $field): bool|float|int|string|null
    {
        $row = $this->rows()[$section][$name] ?? null;
        if ($row === null) {
            return $field->default;
        }

        $value = $row->getValue();

        // A stored value that no longer matches the field (type changed in code) falls back to the default.
        return $value !== null && SettingField::matchesType($field->type, $value, $field->choices) ? $value : $field->default;
    }

    /**
     * @return array<string, array<string, SettingValue>>
     */
    private function rows(): array
    {
        $request = $this->requestStack?->getMainRequest();
        if ($this->rows !== null && $this->isMemoizedFor($request)) {
            return $this->rows;
        }

        $rows = [];
        foreach ($this->repository->findAll() as $row) {
            $rows[$row->getSection()][$row->getName()] = $row;
        }

        $this->rowsRequest = $request instanceof Request ? WeakReference::create($request) : null;

        return $this->rows = $rows;
    }

    private function isMemoizedFor(?Request $request): bool
    {
        if (!$request instanceof Request) {
            return !$this->rowsRequest instanceof WeakReference;
        }

        return $this->rowsRequest?->get() === $request;
    }
}
