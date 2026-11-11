<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures\HostXml;

/**
 * Plain-PHP domain object of a DDD host, mapped in XML (mapping/HostXmlNote.orm.xml): no Doctrine attribute.
 */
class HostXmlNote
{
    /** @phpstan-ignore property.unusedType (assigned by Doctrine through reflection) */
    private ?int $id = null;

    public function __construct(
        private string $title,
    ) {
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }
}
