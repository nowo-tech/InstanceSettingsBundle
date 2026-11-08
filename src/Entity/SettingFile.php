<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\InstanceSettingsBundle\File\DoctrineSettingFileStore;

/**
 * One stored image of an image setting field (1.4): the re-encoded original (`variant` 0) or one of its PNG variants.
 *
 * Content-addressed per field: `(section, name, sha256, variant)` is unique, so the same image uploaded twice into a
 * field is one row; `sha256` is the hash of the re-encoded original (the `isf:` reference), shared by its variants.
 * Rows are bound to the field they were uploaded for, so the file route can never serve one field's image under
 * another field's URL. `orphaned_at` is set when no setting row references the image any more; the purge command
 * deletes it after the retention. The table name gets `doctrine.table_prefix` (REQ-ORM-002).
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: self::UNIQUE_INDEX, columns: ['section', 'name', 'sha256', 'variant'])]
#[ORM\Index(name: self::ORPHANED_INDEX, columns: ['orphaned_at'])]
class SettingFile
{
    /** Base table name (before `doctrine.table_prefix`). */
    public const TABLE = 'instance_setting_file';

    /** Base name of the unique index (before `doctrine.table_prefix`). */
    public const UNIQUE_INDEX = 'uniq_instance_setting_file_ref';

    /** Base name of the `orphaned_at` index (before `doctrine.table_prefix`). */
    public const ORPHANED_INDEX = 'idx_instance_setting_file_orphaned';

    /** `variant` of the original. */
    public const ORIGINAL = 0;

    /** Doctrine assigns this via reflection after persist. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    /** Set by the store's DQL updates ({@see DoctrineSettingFileStore}). */
    #[ORM\Column(name: 'orphaned_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $orphanedAt = null; // @phpstan-ignore property.unusedType (assigned by DQL updates and hydration)

    /**
     * Creates the row of one stored image.
     *
     * @param string $content encoded bytes
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        private string $section,
        #[ORM\Column(type: 'string', length: 64)]
        private string $name,
        #[ORM\Column(type: 'string', length: 64, options: ['fixed' => true])]
        private string $sha256,
        #[ORM\Column(type: 'integer')]
        private int $variant,
        #[ORM\Column(name: 'mime_type', type: 'string', length: 32)]
        private string $mimeType,
        #[ORM\Column(type: 'integer')]
        private int $width,
        #[ORM\Column(type: 'integer')]
        private int $height,
        #[ORM\Column(name: 'byte_size', type: 'integer')]
        private int $byteSize,
        #[ORM\Column(type: 'blob')]
        private mixed $content,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Database id (null until persisted).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Section name.
     */
    public function getSection(): string
    {
        return $this->section;
    }

    /**
     * Field name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * SHA-256 of the re-encoded original.
     */
    public function getSha256(): string
    {
        return $this->sha256;
    }

    /**
     * 0 for the original, else the longest side of the variant.
     */
    public function getVariant(): int
    {
        return $this->variant;
    }

    /**
     * Sniffed type.
     */
    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    /**
     * Width in pixels.
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * Height in pixels.
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Size in bytes.
     */
    public function getByteSize(): int
    {
        return $this->byteSize;
    }

    /**
     * Encoded bytes (a string, or the stream Doctrine hydrates a blob into).
     */
    public function getContent(): mixed
    {
        return $this->content;
    }

    /**
     * Upload time.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * When the image stopped being referenced (null while referenced).
     */
    public function getOrphanedAt(): ?DateTimeImmutable
    {
        return $this->orphanedAt;
    }
}
