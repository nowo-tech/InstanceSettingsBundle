<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\RuntimeEntity;

use Doctrine\ORM\Mapping as ORM;

/**
 * 1.20 (`runtime_setting.enabled` only): the version token of one section for nowo-tech/runtime-setting-contract. It
 * changes with every committed write of the section (admin form, API, import, seed, runtime-setting writer, purge) and is
 * never reused (128 random bits), so a value cached under a rolled-back token never matches again.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
class RuntimeSettingVersion
{
    /** Base table name (before `doctrine.table_prefix`). */
    public const TABLE = 'instance_setting_runtime_version';

    /**
     * Creates the row.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 64)]
        private string $section,
        #[ORM\Column(type: 'string', length: 128)]
        private string $token,
    ) {
    }

    /**
     * Section name.
     */
    public function getSection(): string
    {
        return $this->section;
    }

    /**
     * Current token.
     */
    public function getToken(): string
    {
        return $this->token;
    }
}
