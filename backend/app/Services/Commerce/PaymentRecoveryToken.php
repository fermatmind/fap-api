<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\Order;
use RuntimeException;

final class PaymentRecoveryToken
{
    /**
     * @return array{ok:bool,payload?:array{order_no:string,purpose:string,exp:int},error?:string,message?:string}
     */
    public function verify(string $token, string $expectedOrderNo): array
    {
        $normalizedToken = trim($token);
        $normalizedOrderNo = trim($expectedOrderNo);
        if ($normalizedToken === '' || $normalizedOrderNo === '') {
            return [
                'ok' => false,
                'error' => 'PAYMENT_RECOVERY_TOKEN_INVALID',
                'message' => 'payment recovery token invalid.',
            ];
        }

        $segments = explode('.', $normalizedToken);
        if (count($segments) !== 2) {
            return [
                'ok' => false,
                'error' => 'PAYMENT_RECOVERY_TOKEN_INVALID',
                'message' => 'payment recovery token invalid.',
            ];
        }

        [$payloadSegment, $signatureSegment] = $segments;

        $payloadJson = $this->base64UrlDecode($payloadSegment);
        $signature = $this->base64UrlDecode($signatureSegment);
        if ($payloadJson === null || $signature === null) {
            return [
                'ok' => false,
                'error' => 'PAYMENT_RECOVERY_TOKEN_INVALID',
                'message' => 'payment recovery token invalid.',
            ];
        }

        $expectedSignature = hash_hmac('sha256', $payloadSegment, $this->signingKey(), true);
        if (! hash_equals($expectedSignature, $signature)) {
            return [
                'ok' => false,
                'error' => 'PAYMENT_RECOVERY_TOKEN_INVALID',
                'message' => 'payment recovery token invalid.',
            ];
        }

        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) {
            return [
                'ok' => false,
                'error' => 'PAYMENT_RECOVERY_TOKEN_INVALID',
                'message' => 'payment recovery token invalid.',
            ];
        }

        $orderNo = trim((string) ($payload['order_no'] ?? ''));
        $purpose = trim((string) ($payload['purpose'] ?? ''));
        $exp = (int) ($payload['exp'] ?? 0);

        if (
            $orderNo === ''
            || $purpose !== Order::PAYMENT_RECOVERY_PURPOSE
            || $exp <= 0
            || ! hash_equals($normalizedOrderNo, $orderNo)
        ) {
            return [
                'ok' => false,
                'error' => 'PAYMENT_RECOVERY_TOKEN_INVALID',
                'message' => 'payment recovery token invalid.',
            ];
        }

        if ($exp < time()) {
            return [
                'ok' => false,
                'error' => 'PAYMENT_RECOVERY_TOKEN_EXPIRED',
                'message' => 'payment recovery token expired.',
            ];
        }

        return [
            'ok' => true,
            'payload' => [
                'order_no' => $orderNo,
                'purpose' => $purpose,
                'exp' => $exp,
            ],
        ];
    }

    public function issue(string $orderNo, ?int $ttlSeconds = null): string
    {
        $normalizedOrderNo = trim($orderNo);
        if ($normalizedOrderNo === '') {
            throw new RuntimeException('payment recovery token requires order_no.');
        }

        $ttl = max(60, (int) ($ttlSeconds ?? Order::PAYMENT_RECOVERY_TOKEN_TTL_SECONDS));
        $payload = [
            'order_no' => $normalizedOrderNo,
            'purpose' => Order::PAYMENT_RECOVERY_PURPOSE,
            'exp' => time() + $ttl,
        ];

        $payloadSegment = $this->base64UrlEncode((string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        $signature = hash_hmac('sha256', $payloadSegment, $this->signingKey(), true);

        return $payloadSegment.'.'.$this->base64UrlEncode($signature);
    }

    private function signingKey(): string
    {
        $key = trim((string) config('app.key', ''));
        if ($key === '') {
            throw new RuntimeException('app.key is required for payment recovery token signing.');
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }

            throw new RuntimeException('app.key base64 decode failed for payment recovery token signing.');
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $normalized = strtr($value, '-_', '+/');
        $remainder = strlen($normalized) % 4;
        if ($remainder > 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($normalized, true);

        return is_string($decoded) ? $decoded : null;
    }
}
