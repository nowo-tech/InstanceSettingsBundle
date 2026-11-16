<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Runtime;

use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * 1.20 (audit QS-M-2): a value saved through the instance settings (admin form, API, import) for a field that a bundle
 * registered as a writable runtime setting (`section.field`, source db / db_then_env) also passes that setting's type,
 * bounds and validator, the same rules as `nowo:runtime-setting:set`. Refusals carry the contract's reason (never the
 * value; a generic one for secrets). Removing a value (null / '') is left to the field rules.
 *
 * Registered only with `runtime_setting.enabled` and NowoRuntimeSettingBundle installed. Stateless.
 */
final readonly class RuntimeSettingAdminGuard
{
    /**
     * @param RuntimeSettingRegistry $registry the registered runtime settings
     */
    public function __construct(
        private RuntimeSettingRegistry $registry,
    ) {
    }

    /**
     * @param array<array-key, mixed> $values field => submitted value
     *
     * @return array<string, list<string>> field => messages
     */
    public function violations(string $section, array $values): array
    {
        $violations = [];
        foreach ($values as $name => $value) {
            $id = $section . '.' . $name;
            if (!$this->registry->has($id) || $value === null || $value === '') {
                continue;
            }
            $definition = $this->registry->get($id);
            if (!$definition->isWritable()) {
                continue;
            }
            $raw = match (true) {
                is_string($value) => $value,
                is_bool($value)   => RuntimeSettingType::Bool->format($value),
                is_int($value)    => RuntimeSettingType::Int->format($value),
                is_float($value)  => RuntimeSettingType::Float->format($value),
                default           => null,
            };
            if ($raw === null) {
                continue;
            }
            try {
                $definition->parseAndValidate($raw);
            } catch (InvalidRuntimeSettingValue $e) {
                $violations[(string) $name] = [$e->getMessage()];
            }
        }

        return $violations;
    }
}
