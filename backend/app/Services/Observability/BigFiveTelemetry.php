<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Services\Analytics\EventRecorder;

final class BigFiveTelemetry
{
    public function __construct(
        private readonly EventRecorder $events,
    ) {
    }

    public function recordAttemptStarted(
        int $orgId,
        ?int $userId,
        ?string $anonId,
        string $attemptId,
        string $locale,
        string $region,
        string $packId,
        string $dirVersion
    ): void {
        $this->emit(
            'big5_attempt_started',
            $orgId,
            $userId,
            $anonId,
            $attemptId,
            $packId,
            $dirVersion,
            [
                'locale' => $locale,
                'region' => $region,
                'variant' => 'free',
                'locked' => true,
            ]
        );
    }

    public function recordAttemptSubmitted(
        int $orgId,
        ?int $userId,
        ?string $anonId,
        string $attemptId,
        string $locale,
        string $region,
        string $normsStatus,
        string $normGroupId,
        string $qualityLevel,
        string $variant,
        bool $locked,
        bool $idempotent
    ): void {
        $this->emit(
            'big5_attempt_submitted',
            $orgId,
            $userId,
            $anonId,
            $attemptId,
            'BIG5_OCEAN',
            '',
            [
                'locale' => $locale,
                'region' => $region,
                'norms_status' => strtoupper(trim($normsStatus)),
                'norm_group_id' => $normGroupId,
                'quality_level' => strtoupper(trim($qualityLevel)),
                'variant' => strtolower(trim($variant)),
                'locked' => $locked,
                'idempotent' => $idempotent,
            ]
        );
    }

    public function recordScored(
        int $orgId,
        ?int $userId,
        ?string $anonId,
        string $attemptId,
        string $locale,
        string $region,
        string $normsStatus,
        string $normGroupId,
        string $qualityLevel,
        string $packId,
        string $dirVersion
    ): void {
        $this->emit(
            'big5_scored',
            $orgId,
            $userId,
            $anonId,
            $attemptId,
            $packId,
            $dirVersion,
            [
                'locale' => $locale,
                'region' => $region,
                'norms_status' => strtoupper(trim($normsStatus)),
                'norm_group_id' => $normGroupId,
                'quality_level' => strtoupper(trim($qualityLevel)),
            ]
        );
    }

    public function recordReportComposed(
        int $orgId,
        ?int $userId,
        ?string $anonId,
        string $attemptId,
        string $locale,
        string $region,
        string $normsStatus,
        string $normGroupId,
        string $qualityLevel,
        string $variant,
        bool $locked,
        int $sectionsCount,
        string $packId,
        string $dirVersion
    ): void {
        $this->emit(
            'big5_report_composed',
            $orgId,
            $userId,
            $anonId,
            $attemptId,
            $packId,
            $dirVersion,
            [
                'locale' => $locale,
                'region' => $region,
                'norms_status' => strtoupper(trim($normsStatus)),
                'norm_group_id' => $normGroupId,
                'quality_level' => strtoupper(trim($qualityLevel)),
                'variant' => strtolower(trim($variant)),
                'locked' => $locked,
                'sections_count' => $sectionsCount,
            ]
        );
    }

    public function recordPaymentWebhookProcessed(
        int $orgId,
        ?int $userId,
        ?string $anonId,
        ?string $attemptId,
        string $locale,
        string $region,
        string $status,
        string $skuCode,
        string $offerCode,
        string $provider,
        string $providerEventId,
        string $orderNo
    ): void {
        $this->emit(
            'big5_payment_webhook_processed',
            $orgId,
            $userId,
            $anonId,
            $attemptId ?? '',
            'BIG5_OCEAN',
            '',
            [
                'locale' => $locale,
                'region' => $region,
                'variant' => 'full',
                'locked' => false,
                'sku_code' => strtoupper(trim($skuCode)),
                'offer_code' => strtoupper(trim($offerCode)),
                'provider' => strtolower(trim($provider)),
                'provider_event_id' => trim($providerEventId),
                'order_no' => trim($orderNo),
                'webhook_status' => strtolower(trim($status)),
            ]
        );
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function emit(
        string $eventCode,
        int $orgId,
        ?int $userId,
        ?string $anonId,
        string $attemptId,
        string $packId,
        string $dirVersion,
        array $meta
    ): void {
        $normalizedMeta = $this->withRequiredMeta($meta);

        $attemptId = trim($attemptId);
        $packId = trim($packId);
        $dirVersion = trim($dirVersion);
        $anonId = $anonId !== null && trim($anonId) !== '' ? trim($anonId) : null;

        if (($normalizedMeta['attempt_id'] ?? null) === null && $attemptId !== '') {
            $normalizedMeta['attempt_id'] = $attemptId;
        }
        if (($normalizedMeta['anon_id'] ?? null) === null && $anonId !== null) {
            $normalizedMeta['anon_id'] = $anonId;
        }

        $this->events->record($eventCode, $userId, $normalizedMeta, [
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'attempt_id' => $attemptId !== '' ? $attemptId : null,
            'pack_id' => $packId !== '' ? $packId : null,
            'dir_version' => $dirVersion !== '' ? $dirVersion : null,
        ]);
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function withRequiredMeta(array $meta): array
    {
        return array_merge([
            'scale_code' => 'BIG5_OCEAN',
            'attempt_id' => null,
            'anon_id' => null,
            'locale' => null,
            'region' => null,
            'norms_status' => null,
            'norm_group_id' => null,
            'quality_level' => null,
            'variant' => null,
            'locked' => null,
            'sku_code' => null,
            'offer_code' => null,
        ], $meta);
    }
}
