<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Seed;

use RuntimeException;

use function count;
use function implode;
use function sprintf;

/**
 * A seed file (or seed data) refused by {@see SeedFileSeeder} before anything was written (1.7, IS-3).
 *
 * Carries one line per problem: `<where>: <code> (<English sentence>)`, where `<where>` is `file`, a section or a
 * `<section>.<field>` key and `<code>` a stable reason code. Never a value nor the content of a file.
 */
final class SeedFileException extends RuntimeException
{
    /** The seed path is empty, absolute, a URL / stream wrapper (`phar://`, `http://`, `data:` …) or holds a NUL byte. */
    public const PATH_INVALID = 'path_invalid';

    /** The path does not exist (or a parent directory is not readable). */
    public const PATH_NOT_FOUND = 'path_not_found';

    /** The resolved path (symlinks followed) is outside the allowed base directory. */
    public const PATH_OUTSIDE_BASE = 'path_outside_base';

    /** The path is not a regular readable file. */
    public const NOT_A_FILE = 'not_a_file';

    /** The seed file is larger than {@see SeedFileSeeder::MAX_SEED_FILE_BYTES}. */
    public const FILE_TOO_LARGE = 'file_too_large';

    /** The seed file extension is not `.yaml`, `.yml` or `.json`. */
    public const UNSUPPORTED_FORMAT = 'unsupported_format';

    /** A YAML seed file but symfony/yaml is not installed. */
    public const YAML_UNAVAILABLE = 'yaml_unavailable';

    /** The file does not parse (YAML / JSON syntax) or is not a mapping. */
    public const PARSE_ERROR = 'parse_error';

    /** `schema` is not {@see SeedFileSeeder::SCHEMA}. */
    public const INVALID_SCHEMA = 'invalid_schema';

    /** `version` is missing or not a supported version. */
    public const UNSUPPORTED_VERSION = 'unsupported_version';

    /** A top-level key other than `schema`, `version`, `settings`, `files`. */
    public const UNKNOWN_KEY = 'unknown_key';

    /** `settings` / `files` (or one of their sections) is not a mapping. */
    public const NOT_A_MAPPING = 'not_a_mapping';

    /** No registered section of that name. */
    public const UNKNOWN_SECTION = 'unknown_section';

    /** The section declares no such field. */
    public const UNKNOWN_FIELD = 'unknown_field';

    /** A secret field: secrets are never accepted in a seed file (it is committed to a repository). */
    public const SECRET_FIELD = 'secret_field';

    /** An image field under `settings` (images go under `files`, as a path). */
    public const IMAGE_FIELD_IN_SETTINGS = 'image_field_in_settings';

    /** A plain field under `files` (only image fields take a path). */
    public const NOT_AN_IMAGE_FIELD = 'not_an_image_field';

    /** A plain value that is not a scalar (list, mapping). */
    public const NOT_SCALAR = 'not_scalar';

    /** A plain value refused by the field type / constraints (the messages are dropped: they may echo the value). */
    public const INVALID_VALUE = 'invalid_value';

    /** An SVG image (refused like an upload; owner decision spec 220: no SVG). */
    public const SVG_NOT_SUPPORTED = 'svg_not_supported';

    /** The image section needs the file store (`files.enabled` / `brand.enabled`). */
    public const FILES_DISABLED = 'files_disabled';

    /** No seed file given and `seed.file` is not configured. */
    public const NO_FILE = 'no_file';

    private const MESSAGES = [
        self::PATH_INVALID            => 'Paths must be relative to the seed file, without a URL scheme or stream wrapper.',
        self::PATH_NOT_FOUND          => 'The file does not exist.',
        self::PATH_OUTSIDE_BASE       => 'The file is outside the allowed base directory (seed.base_dir).',
        self::NOT_A_FILE              => 'The path is not a readable regular file.',
        self::FILE_TOO_LARGE          => 'The seed file is too large.',
        self::UNSUPPORTED_FORMAT      => 'Seed files must be .yaml, .yml or .json.',
        self::YAML_UNAVAILABLE        => 'A YAML seed file needs symfony/yaml (composer require symfony/yaml), or use .json.',
        self::PARSE_ERROR             => 'The seed file does not parse into a mapping.',
        self::INVALID_SCHEMA          => 'The "schema" key must be "' . SeedFileSeeder::SCHEMA . '".',
        self::UNSUPPORTED_VERSION     => 'The "version" key must be a supported seed format version.',
        self::UNKNOWN_KEY             => 'Unknown top-level key (allowed: schema, version, settings, files).',
        self::NOT_A_MAPPING           => 'A mapping of names to values is expected here.',
        self::UNKNOWN_SECTION         => 'No settings section of that name is registered.',
        self::UNKNOWN_FIELD           => 'The section declares no such field.',
        self::SECRET_FIELD            => 'Secret fields cannot be seeded from a file: set them in the admin UI or from the environment.',
        self::IMAGE_FIELD_IN_SETTINGS => 'Image fields go under "files" as a path relative to the seed file.',
        self::NOT_AN_IMAGE_FIELD      => 'Only image fields go under "files".',
        self::NOT_SCALAR              => 'A string, number or boolean is expected.',
        self::INVALID_VALUE           => 'The value is not valid for this field.',
        self::SVG_NOT_SUPPORTED       => 'SVG images are not accepted: use a PNG or WebP file.',
        self::FILES_DISABLED          => 'Image fields need files.enabled (or brand.enabled).',
        self::NO_FILE                 => 'Give the seed file path, or configure nowo_instance_settings.seed.file.',
    ];

    /**
     * @param list<string> $errors `<where>: <code> (<sentence>)` lines
     * @param string|null $reason the reason code of a single-problem refusal
     */
    public function __construct(
        public readonly array $errors,
        public readonly ?string $reason = null,
    ) {
        parent::__construct(count($errors) === 1 ? 'Seed refused: ' . $errors[0] : sprintf('Seed refused (%d problems): %s', count($errors), implode('; ', $errors)));
    }

    /**
     * A refusal with a single problem.
     */
    public static function single(string $where, string $code, ?string $sentence = null): self
    {
        return new self([self::line($where, $code, $sentence)], $code);
    }

    /**
     * One problem line: `<where>: <code> (<sentence>)`.
     */
    public static function line(string $where, string $code, ?string $sentence = null): string
    {
        return sprintf('%s: %s (%s)', $where, $code, $sentence ?? self::MESSAGES[$code] ?? $code);
    }
}
