<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures\HostEntity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entity of a standard (attribute-mapped) host application: its pending changes must never be flushed by the bundle.
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_note')]
class HostNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        public string $title,
    ) {
    }
}
