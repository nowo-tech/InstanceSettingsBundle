<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;

/**
 * Value type of a {@see SettingField}: drives value normalization, the typed getters and the admin form widget.
 */
enum SettingType: string
{
    /** Single-line string (TextType). */
    case String = 'string';

    /** Multi-line string (TextareaType). */
    case Text = 'text';

    /** E-mail address string (EmailType + Email constraint). */
    case Email = 'email';

    /** Absolute http(s) URL string (UrlType + Url constraint). */
    case Url = 'url';

    /** Boolean switch (CheckboxType). */
    case Bool = 'bool';

    /** Integer (IntegerType). */
    case Int = 'int';

    /** Float (NumberType). */
    case Float = 'float';

    /** One of the declared choices (ChoiceType); values are strings or integers. */
    case Choice = 'choice';

    /**
     * Raster image (PNG, JPEG, still WebP) uploaded through the admin form or imported (1.4). The stored value is a
     * reference `isf:<sha256>` to the re-encoded file; read it with {@see InstanceSettingsInterface::getFile()}.
     */
    case Image = 'image';

    /**
     * Whether values of this type are strings (the only types that can be secret).
     */
    public function isString(): bool
    {
        return match ($this) {
            self::String, self::Text, self::Email, self::Url => true,
            default                                          => false,
        };
    }
}
