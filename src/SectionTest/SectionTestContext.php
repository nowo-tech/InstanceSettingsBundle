<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use Closure;
use InvalidArgumentException;
use LogicException;

use function in_array;
use function is_string;
use function sprintf;

/**
 * What a {@see SettingsSectionTesterInterface} gets (1.18): the section, the field group under test (null = the whole
 * section) and read access to the STORED values of the section — the values Save wrote, never what is typed in the form.
 *
 * Secrets are decrypted on demand, one field per call, and only while the test runs: the bundle closes the context when
 * the tester returns, and every read after that throws, so a tester that keeps the context cannot read a secret later.
 * The context itself never holds a value (it reads through the bundle's store).
 *
 * Framework-free (REQ-ARCH-001): plain strings, scalars and a closure.
 */
final class SectionTestContext
{
    private bool $open = true;

    /**
     * @param string $section section name
     * @param string|null $group field group code under test (null = the whole section)
     * @param list<string> $fields every field name of the section, in order
     * @param list<string> $secretFields the secret field names of the section
     * @param list<string> $groupFields the field names of the group under test (every field for a section test)
     * @param Closure(string, bool): (bool|float|int|string|null) $read reads one stored value: field name, secret?
     * @param int $timeBudgetSeconds time budget of the call ({@see SettingsSectionTesterInterface::TIME_BUDGET_SECONDS})
     */
    public function __construct(
        public readonly string $section,
        public readonly ?string $group,
        private readonly array $fields,
        private readonly array $secretFields,
        private readonly array $groupFields,
        private readonly Closure $read,
        private readonly int $timeBudgetSeconds = SettingsSectionTesterInterface::TIME_BUDGET_SECONDS,
    ) {
    }

    /**
     * Every field name of the section, in order.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * The field names of the group under test (every field of the section for a section test).
     *
     * @return list<string>
     */
    public function groupFields(): array
    {
        return $this->groupFields;
    }

    /**
     * Effective stored value of a plain field (the stored value, else the field default).
     *
     * @throws InvalidArgumentException for an unknown field or a secret field (use {@see self::secret()})
     * @throws LogicException once the test call has returned
     */
    public function value(string $field): bool|float|int|string|null
    {
        $this->assertField($field, false);

        return ($this->read)($field, false);
    }

    /**
     * Effective stored value of a plain field as a string ('' and null give null).
     */
    public function string(string $field): ?string
    {
        $value = $this->value($field);
        if (in_array($value, [null, '', false], true)) {
            return null;
        }

        return is_string($value) ? $value : (string) ($value === true ? '1' : $value);
    }

    /**
     * Decrypted stored secret of a secret field, or null when none is stored. Keep it in a local variable for the call
     * only: never in a property, a log line, an exception message or a check parameter.
     *
     * @throws InvalidArgumentException for an unknown field or a field that is not a secret
     * @throws LogicException once the test call has returned
     */
    public function secret(string $field): ?string
    {
        $this->assertField($field, true);
        $value = ($this->read)($field, true);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Whether a secret is stored for a secret field.
     */
    public function hasSecret(string $field): bool
    {
        return $this->secret($field) !== null;
    }

    /**
     * Time budget of the call, in seconds: use it as the timeout of every network call.
     */
    public function timeBudgetSeconds(): int
    {
        return $this->timeBudgetSeconds;
    }

    /**
     * Whether the context still answers (false once the test call has returned).
     */
    public function isOpen(): bool
    {
        return $this->open;
    }

    /**
     * Ends the test call: every read after this throws.
     *
     * @internal called by the bundle when the tester returns
     */
    public function close(): void
    {
        // @igor-ignore - Per-call value object built for one test call (never a service); closing it ends that call.
        $this->open = false;
    }

    private function assertField(string $field, bool $secret): void
    {
        if (!$this->open) {
            throw new LogicException('The section test context is closed: stored values are read only while the test runs.');
        }
        if (!in_array($field, $this->fields, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a field of the section under test.', $field));
        }
        if (in_array($field, $this->secretFields, true) !== $secret) {
            throw new InvalidArgumentException(sprintf($secret ? 'Field "%s" is not a secret: use value().' : 'Field "%s" is a secret: use secret().', $field));
        }
    }
}
