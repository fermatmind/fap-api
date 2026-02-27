<?php

declare(strict_types=1);

namespace App\Services\Commerce\Checkout;

use Illuminate\Support\Facades\Http;

final class LemonSqueezyCheckoutService
{
    /**
     * @return array<string,mixed>
     */
    public function createCheckout(
        string $orderNo,
        ?string $attemptId,
        int $amountCents,
        string $currency,
        ?string $contactEmail = null
    ): array {
        $apiKey = trim((string) config('services.lemonsqueezy.api_key', ''));
        $storeId = trim((string) config('services.lemonsqueezy.store_id', ''));
        $variantId = trim((string) config('services.lemonsqueezy.variant_id', ''));
        $apiBase = rtrim(trim((string) config('services.lemonsqueezy.api_base', 'https://api.lemonsqueezy.com/v1')), '/');

        if ($apiKey === '' || $storeId === '' || $variantId === '') {
            return [
                'ok' => false,
                'error_code' => 'PAYMENT_PROVIDER_NOT_CONFIGURED',
                'message' => 'lemonsqueezy is not configured.',
            ];
        }

        $customData = [
            'order_no' => $orderNo,
            'amount_cents' => $amountCents,
            'currency' => strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'USD',
        ];
        $attemptId = trim((string) $attemptId);
        if ($attemptId !== '') {
            $customData['attempt_id'] = $attemptId;
        }
        $contactEmail = trim((string) $contactEmail);
        if ($contactEmail !== '') {
            $customData['email'] = $contactEmail;
        }

        $attributes = [
            'checkout_data' => [
                'custom' => $customData,
            ],
        ];

        $redirectUrl = trim((string) config('services.lemonsqueezy.checkout_redirect_url', ''));
        if ($redirectUrl !== '') {
            $attributes['product_options'] = [
                'redirect_url' => $redirectUrl,
            ];
        }

        $payload = [
            'data' => [
                'type' => 'checkouts',
                'attributes' => $attributes,
                'relationships' => [
                    'store' => [
                        'data' => [
                            'type' => 'stores',
                            'id' => $storeId,
                        ],
                    ],
                    'variant' => [
                        'data' => [
                            'type' => 'variants',
                            'id' => $variantId,
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->withToken($apiKey)
                ->post($apiBase.'/checkouts', $payload);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error_code' => 'PAYMENT_PROVIDER_ERROR',
                'message' => 'lemonsqueezy checkout request failed.',
                'details' => $e->getMessage(),
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error_code' => 'PAYMENT_PROVIDER_ERROR',
                'message' => 'lemonsqueezy checkout request failed.',
                'details' => $response->json() ?: $response->body(),
                'status' => $response->status(),
            ];
        }

        $checkoutUrl = trim((string) data_get($response->json(), 'data.attributes.url', ''));
        if ($checkoutUrl === '') {
            return [
                'ok' => false,
                'error_code' => 'PAYMENT_PROVIDER_ERROR',
                'message' => 'lemonsqueezy checkout url missing.',
                'details' => $response->json(),
            ];
        }

        return [
            'ok' => true,
            'url' => $checkoutUrl,
        ];
    }
}
