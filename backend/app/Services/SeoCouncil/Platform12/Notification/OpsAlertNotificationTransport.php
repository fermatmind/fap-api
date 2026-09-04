<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Notification;

use App\Services\Ops\OpsAlertService;

final class OpsAlertNotificationTransport implements Platform12NotificationTransport
{
    public function send(string $notificationId, array $sanitizedPayload): void
    {
        OpsAlertService::send(sprintf(
            '[SEO Council alert] notification=%s event=%s severity=%s subject_hash=%s state=%s',
            $notificationId,
            (string) ($sanitizedPayload['event_type'] ?? 'unknown'),
            (string) ($sanitizedPayload['severity'] ?? 'unknown'),
            (string) ($sanitizedPayload['subject_hash'] ?? 'unknown'),
            (string) ($sanitizedPayload['state'] ?? 'unknown'),
        ));
    }
}
