<?php

namespace App\Services\Commerce\Webhook;

use App\Internal\Commerce\PaymentWebhookHandlerCore;

class WebhookPrecheckService
{
    public function __construct(private PaymentWebhookHandlerCore $core)
    {
    }

    public function handle(
        string $provider,
        array $payload,
        int $orgId,
        ?string $userId,
        ?string $anonId,
        bool $signatureOk,
        array $payloadMeta,
        string $rawPayloadSha256,
        int $rawPayloadBytes
    ): array {
        $provider = strtolower(trim($provider));
        if ($provider === 'stub' && ! $this->core->isStubEnabled()) {
            return ['result' => $this->core->notFound('PROVIDER_DISABLED', 'not found.')];
        }

        $gateway = $this->core->gatewayFor($provider);
        if (! $gateway) {
            return ['result' => $this->core->badRequest('PROVIDER_NOT_SUPPORTED', 'provider not supported.')];
        }

        $normalized = $gateway->normalizePayload($payload);
        $providerEventId = trim((string) ($normalized['provider_event_id'] ?? ''));
        $orderNo = trim((string) ($normalized['order_no'] ?? ''));
        $eventType = $this->core->normalizeEventType($normalized);
        if ($providerEventId === '' || $orderNo === '') {
            return ['result' => $this->core->badRequest('PAYLOAD_INVALID', 'provider_event_id and order_no are required.')];
        }

        $receivedAt = now();
        $resolvedPayloadMeta = $this->core->resolvePayloadMeta($payload, $payloadMeta, $rawPayloadSha256, $rawPayloadBytes);
        $payloadSummary = $this->core->buildPayloadSummary(
            $normalized,
            $eventType,
            $resolvedPayloadMeta['sha256'],
            $resolvedPayloadMeta['size_bytes']
        );
        $payloadSummaryJson = $this->core->encodePayloadSummary($payloadSummary);
        $payloadExcerpt = $this->core->buildPayloadExcerpt($payloadSummaryJson);

        return [
            'provider' => $provider,
            'normalized' => $normalized,
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'event_type' => $eventType,
            'org_id' => $orgId,
            'normalized_org_id' => max(0, $orgId),
            'user_id' => $userId,
            'anon_id' => $anonId,
            'signature_ok' => $signatureOk,
            'received_at' => $receivedAt,
            'resolved_payload_meta' => $resolvedPayloadMeta,
            'payload_summary_json' => $payloadSummaryJson,
            'payload_excerpt' => $payloadExcerpt,
        ];
    }
}
