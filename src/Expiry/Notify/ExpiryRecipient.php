<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use InvalidArgumentException;

use function mb_check_encoding;
use function mb_strlen;
use function preg_match;
use function str_contains;
use function trim;

/**
 * One person who receives the expiry reminders (1.21, KIT-88), returned by the host's
 * {@see ExpiryRecipientProviderInterface}. Framework-free: no user entity, no Symfony Mime address.
 */
final readonly class ExpiryRecipient
{
    /** Longest id (the sent-marker column and NotifyKit's `RecipientRef` id). */
    public const MAX_ID_LENGTH = 128;

    /**
     * @param string $id stable, opaque id of the person (user id or UUID): the reminder is sent once per id; also the
     *                   NotifyKit recipient id
     * @param string|null $email mail address; null = no mail for this person (the bell only)
     * @param string|null $locale locale of the mail and the bell texts (`es`, `pt_BR`); null = the default locale
     * @param bool $canOpenSettings whether the link goes to the settings page (`link_route`); false = `fallback_link_route`
     *                              (a manager who cannot open the settings, sent to the start page)
     */
    public function __construct(
        public string $id,
        public ?string $email = null,
        public ?string $locale = null,
        public bool $canOpenSettings = true,
    ) {
        if (trim($id) !== $id || $id === '' || !mb_check_encoding($id, 'UTF-8') || mb_strlen($id, 'UTF-8') > self::MAX_ID_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
            throw new InvalidArgumentException('ExpiryRecipient id must be a non-empty UTF-8 string of at most 128 characters, without control characters or surrounding spaces.');
        }
        if ($email !== null && ($email === '' || !str_contains($email, '@') || preg_match('/[\x00-\x20\x7F]/', $email) === 1)) {
            throw new InvalidArgumentException('ExpiryRecipient email must be an address without spaces or control characters, or null.');
        }
        if ($locale !== null && preg_match('/^[a-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/D', $locale) !== 1) {
            throw new InvalidArgumentException('ExpiryRecipient locale must be a locale such as "es" or "pt_BR", or null.');
        }
    }
}
