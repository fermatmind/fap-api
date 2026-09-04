<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Notification;

interface Platform12NotificationTransport
{
    /** @param array<string, mixed> $sanitizedPayload */
    public function send(string $notificationId, array $sanitizedPayload): void;
}
