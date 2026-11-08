<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Brand;

use Stringable;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A default brand text given as a translation key (`brand.defaults.eyebrow: {key, domain}`): translated in the
 * viewer's locale while nothing is stored (spec 220 §6). Stringable (the key and domain), so the brand version
 * changes when either changes (audit IP-I1 / IP-I2).
 */
final readonly class TranslatableBrandText implements TranslatableInterface, Stringable
{
    /**
     * @param string $key translation key
     * @param string $domain translation domain
     */
    public function __construct(
        public string $key,
        public string $domain = 'messages',
    ) {
    }

    /**
     * The translated text.
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->key, [], $this->domain, $locale);
    }

    /**
     * `<domain>:<key>` (what the brand version hashes).
     */
    public function __toString(): string
    {
        return $this->domain . ':' . $this->key;
    }
}
