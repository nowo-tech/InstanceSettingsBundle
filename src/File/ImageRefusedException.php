<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\File;

use InvalidArgumentException;

/**
 * An image upload or import refused by {@see ImageProcessor}. The message is a stable reason code (never the content,
 * never the client file name); {@see self::describe()} gives the English sentence shown next to the form field.
 */
final class ImageRefusedException extends InvalidArgumentException
{
    /** Nothing was uploaded, or the file is empty. */
    public const EMPTY = 'empty';

    /** The upload did not complete (PHP upload error). */
    public const UPLOAD_FAILED = 'upload_failed';

    /** Larger than the field's `maxBytes`. */
    public const TOO_LARGE = 'too_large';

    /** Not a PNG, JPEG or WebP by its content (magic bytes and header disagree, or the file does not decode). */
    public const INVALID_IMAGE = 'invalid_image';

    /** A valid image of a type the field does not accept. */
    public const TYPE_NOT_ALLOWED = 'type_not_allowed';

    /** An animated WebP (only still images are accepted). */
    public const ANIMATED = 'animated';

    /** More pixels than the hard cap or than the memory budget allows (checked from the header, before decoding). */
    public const TOO_MANY_PIXELS = 'too_many_pixels';

    /** Wider or taller than the field allows. */
    public const DIMENSIONS_TOO_LARGE = 'dimensions_too_large';

    /** Narrower or shorter than the field requires. */
    public const DIMENSIONS_TOO_SMALL = 'dimensions_too_small';

    /** Not square within 2 % for a `square` field. */
    public const NOT_SQUARE = 'not_square';

    private const MESSAGES = [
        self::EMPTY                => 'The file is empty.',
        self::UPLOAD_FAILED        => 'The file could not be uploaded.',
        self::TOO_LARGE            => 'The file is too large.',
        self::INVALID_IMAGE        => 'The file is not a valid PNG, JPEG or WebP image.',
        self::TYPE_NOT_ALLOWED     => 'This image type is not accepted here.',
        self::ANIMATED             => 'Animated images are not accepted.',
        self::TOO_MANY_PIXELS      => 'The image has too many pixels.',
        self::DIMENSIONS_TOO_LARGE => 'The image is too wide or too tall.',
        self::DIMENSIONS_TOO_SMALL => 'The image is too narrow or too short.',
        self::NOT_SQUARE           => 'The image must be square.',
    ];

    /**
     * @param string $reason one of the reason constants
     */
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }

    /**
     * Reason code.
     */
    public function reason(): string
    {
        return $this->getMessage();
    }

    /**
     * English sentence of the reason (form errors), without any detail of the file.
     */
    public function describe(): string
    {
        return self::MESSAGES[$this->getMessage()] ?? self::MESSAGES[self::INVALID_IMAGE];
    }
}
