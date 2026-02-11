<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

trait SignedBillingWebhook
{
    protected function postSignedBillingWebhook(array $payload, array $headers = []): TestResponse
    {
        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($rawBody)) {
            self::fail('json_encode payload failed.');
        }

        $secret = trim((string) config('services.billing.webhook_secret', ''));
        if ($secret === '') {
            $secret = 'billing_secret';
            config(['services.billing.webhook_secret' => $secret]);
        }

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ];

        foreach ($headers as $name => $value) {
            $normalized = strtoupper(str_replace('-', '_', (string) $name));
            if ($normalized === 'CONTENT_TYPE') {
                $server['CONTENT_TYPE'] = (string) $value;
                continue;
            }
            if ($normalized === 'ACCEPT') {
                $server['HTTP_ACCEPT'] = (string) $value;
                continue;
            }
            $server['HTTP_' . $normalized] = (string) $value;
        }

        return $this->call(
            'POST',
            '/api/v0.3/webhooks/payment/billing',
            [],
            [],
            [],
            $server,
            $rawBody,
        );
    }
}
