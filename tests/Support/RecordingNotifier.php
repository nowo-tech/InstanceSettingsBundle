<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\NotifyResult;

/**
 * NotifyKit notifier keeping the requests (1.21 expiry notifier tests).
 */
final class RecordingNotifier implements NotifierInterface
{
    /** @var list<NotificationRequest> */
    public array $requests = [];

    public function notify(NotificationRequest $request): NotifyResult
    {
        $this->requests[] = $request;

        return new NotifyResult(written: 1);
    }
}
