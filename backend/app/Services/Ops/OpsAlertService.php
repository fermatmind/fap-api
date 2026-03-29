<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Facades\Http;

class OpsAlertService
{
    public static function send(string $message): void
    {
        $url = trim((string) config('ops.alert.webhook', ''));
        if ($url === '') {
            return;
        }

        Http::post($url, [
            'text' => $message,
        ]);
    }
}
