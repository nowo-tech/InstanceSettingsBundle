<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Secret field with a constraint (what a mailer kit does with a DSN validator).
 */
final class SecretConstraintSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'transport';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getFields(): iterable
    {
        yield SettingField::secret('dsn', 'mailer.dsn', constraints: [new Regex(pattern: '#^smtps?://#', message: 'Use an smtp:// or smtps:// DSN.')]);
    }
}
