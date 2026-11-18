<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use Nowo\NotifyKitBundle\Contract\CategoryDefinition;
use Nowo\NotifyKitBundle\Contract\TypeDefinition;
use Nowo\NotifyKitBundle\Contract\TypeProviderInterface;
use Nowo\NotifyKitBundle\Contract\Urgency;

/**
 * Declares the two NotifyKit types of the expiry reminders (1.21, KIT-88) and, unless the host declares it itself
 * (`notify.notify_kit.declare_category: false`), their category. In-app only: the mail is the bundle's own channel.
 * Title keys in the `NowoInstanceSettingsBundle` domain.
 */
final readonly class NotifyKitExpiryTypeProvider implements TypeProviderInterface
{
    /**
     * @param string $category category key (`notify.notify_kit.category`)
     * @param bool $declareCategory declare the category (false = the host declares it)
     */
    public function __construct(
        private string $category = 'instance_settings',
        private bool $declareCategory = true,
    ) {
    }

    /**
     * `instance_settings.secret_expiring` and `instance_settings.secret_expired`.
     */
    public function types(): iterable
    {
        yield new TypeDefinition(NotifyKitExpiryNoticeChannel::TYPE_EXPIRING, $this->category, 'tabler:key', 'secret_expiry_notice.notify_expiring', null, [TypeDefinition::IN_APP => true]);
        yield new TypeDefinition(NotifyKitExpiryNoticeChannel::TYPE_EXPIRED, $this->category, 'tabler:key-off', 'secret_expiry_notice.notify_expired', null, [TypeDefinition::IN_APP => true], urgency: Urgency::High);
    }

    /**
     * The category, when declared here.
     */
    public function categories(): iterable
    {
        if ($this->declareCategory) {
            yield new CategoryDefinition($this->category, 'secret_expiry_notice.notify_category');
        }
    }
}
