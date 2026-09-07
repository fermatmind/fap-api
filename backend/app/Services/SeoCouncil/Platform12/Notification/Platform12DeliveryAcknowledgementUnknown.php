<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Notification;

final class Platform12DeliveryAcknowledgementUnknown extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('DELIVERY_ACK_UNKNOWN');
    }
}
