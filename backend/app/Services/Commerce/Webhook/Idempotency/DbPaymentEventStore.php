<?php

declare(strict_types=1);

namespace App\Services\Commerce\Webhook\Idempotency;

use App\Services\Commerce\Webhook\Contracts\PaymentEventStoreInterface;
use Illuminate\Support\Facades\DB;

final class DbPaymentEventStore implements PaymentEventStoreInterface
{
    public function begin(string $provider, string $providerEventId, array $seed): array
    {
        $inserted = (int) DB::table('payment_events')->insertOrIgnore($seed);
        $event = DB::table('payment_events')
            ->where('provider', $provider)
            ->where('provider_event_id', $providerEventId)
            ->first();

        return [
            'ok' => $event !== null,
            'duplicate' => $inserted === 0,
            'event' => is_object($event) ? (array) $event : [],
        ];
    }
}
