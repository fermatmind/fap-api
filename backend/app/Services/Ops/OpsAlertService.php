<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Facades\Http;

class OpsAlertService
{
    public static function send(string $message, bool $requireAcknowledgement = false): void
    {
        $url = trim((string) config('ops.alert.webhook', ''));
        if ($url === '') {
            if ($requireAcknowledgement) {
                throw new \RuntimeException('OPS_ALERT_TRANSPORT_UNAVAILABLE');
            }

            return;
        }

        if ($requireAcknowledgement) {
            Http::connectTimeout(3)->timeout(10)->post($url, ['text' => $message])->throw();

            return;
        }
        Http::post($url, [
            'text' => $message,
        ]);
    }
}
