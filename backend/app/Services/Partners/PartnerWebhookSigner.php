<?php

declare(strict_types=1);

namespace App\Services\Partners;

final class PartnerWebhookSigner
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array{
     *     payload_raw:string,
     *     timestamp:int,
     *     signature:string,
     *     headers:array{X-FM-Timestamp:string,X-FM-Signature:string}
     * }
     */
    public function signPayload(array $payload, string $secret, ?int $timestamp = null): array
    {
        $secret = trim($secret);
        if ($secret === '') {
            throw new \InvalidArgumentException('webhook secret is required.');
        }

        $ts = $timestamp ?? time();
        if ($ts <= 0) {
            $ts = time();
        }

        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($rawBody)) {
            $rawBody = '{}';
        }

        $signature = hash_hmac('sha256', $ts.'.'.$rawBody, $secret);

        return [
            'payload_raw' => $rawBody,
            'timestamp' => $ts,
            'signature' => $signature,
            'headers' => [
                'X-FM-Timestamp' => (string) $ts,
                'X-FM-Signature' => $signature,
            ],
        ];
    }
}
