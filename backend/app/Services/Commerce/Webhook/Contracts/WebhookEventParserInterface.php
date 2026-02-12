<?php

declare(strict_types=1);

namespace App\Services\Commerce\Webhook\Contracts;

use App\Services\Commerce\Webhook\DTO\NormalizedPaymentEvent;

interface WebhookEventParserInterface
{
    public function provider(): string;

    public function parse(array $payload): NormalizedPaymentEvent;
}
