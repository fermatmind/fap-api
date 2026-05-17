<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class RevenueDailyBuilder
{
    private const BACKEND_PURCHASE_TRUTH_SOURCE = 'backend_orders_payment_benefits';

    public function __construct(
        private readonly SourceEngineNormalizer $sourceEngineNormalizer,
        private readonly InternalTrafficFilter $internalTrafficFilter,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $records, array $options = []): array
    {
        $includeInternal = (bool) ($options['include_internal'] ?? false);
        $revenue = [];
        $cluster = [];
        $ignoredNonBackendTruth = 0;
        $excluded = 0;

        foreach ($records as $record) {
            if (($record['truth_source'] ?? self::BACKEND_PURCHASE_TRUTH_SOURCE) !== self::BACKEND_PURCHASE_TRUTH_SOURCE) {
                $ignoredNonBackendTruth++;

                continue;
            }

            if ($this->internalTrafficFilter->shouldExclude($record, $includeInternal) || (bool) ($record['is_test'] ?? false)) {
                $excluded++;

                continue;
            }

            if (! $this->isSuccessfulPurchaseStatus($record['status'] ?? null)) {
                continue;
            }

            $date = $this->reportDate($record['occurred_at'] ?? null);
            $sourceEngine = $this->sourceEngineNormalizer->normalizeFromPayload($record);
            $currency = strtoupper((string) ($record['currency'] ?? 'CNY'));
            $revenueCents = max(0, (int) ($record['revenue_cents'] ?? 0));
            $sessionsProxy = max(0, (int) ($record['sessions_proxy_count'] ?? $record['result_view_count'] ?? 0));

            $revenueKey = $this->key([
                $date,
                $record['canonical_url_hash'] ?? '',
                $record['locale'] ?? '',
                $record['page_entity_type'] ?? '',
                $record['cluster'] ?? '',
                $sourceEngine,
                $currency,
            ]);

            $revenue[$revenueKey] ??= [
                'report_date' => $date,
                'canonical_url_hash' => $this->safeNullableString($record['canonical_url_hash'] ?? null),
                'locale' => $this->safeNullableString($record['locale'] ?? null),
                'page_entity_type' => $this->safeNullableString($record['page_entity_type'] ?? null),
                'cluster' => $this->safeNullableString($record['cluster'] ?? null),
                'source_engine' => $sourceEngine,
                'orders_count' => 0,
                'purchase_count' => 0,
                'revenue_cents' => 0,
                'currency' => $currency,
                'aov_cents' => null,
                'rpv_proxy_cents' => null,
                'purchase_rate_ppm' => null,
                '_sessions_proxy_count' => 0,
            ];

            $revenue[$revenueKey]['orders_count']++;
            $revenue[$revenueKey]['purchase_count']++;
            $revenue[$revenueKey]['revenue_cents'] += $revenueCents;
            $revenue[$revenueKey]['_sessions_proxy_count'] += $sessionsProxy;

            $clusterValue = $this->safeNullableString($record['cluster'] ?? null);
            if ($clusterValue !== null && $clusterValue !== '') {
                $clusterKey = $this->key([$date, $clusterValue, $record['locale'] ?? '', $sourceEngine, $currency]);
                $cluster[$clusterKey] ??= [
                    'report_date' => $date,
                    'cluster' => $clusterValue,
                    'locale' => $this->safeNullableString($record['locale'] ?? null),
                    'source_engine' => $sourceEngine,
                    'landing_event_count' => 0,
                    'start_attempt_count' => 0,
                    'submit_attempt_count' => 0,
                    'purchase_count' => 0,
                    'revenue_cents' => 0,
                    'currency' => $currency,
                ];
                $cluster[$clusterKey]['purchase_count']++;
                $cluster[$clusterKey]['revenue_cents'] += $revenueCents;
            }
        }

        foreach ($revenue as &$row) {
            $ordersCount = max(1, (int) $row['orders_count']);
            $sessionsProxy = (int) $row['_sessions_proxy_count'];

            $row['aov_cents'] = intdiv((int) $row['revenue_cents'], $ordersCount);

            if ($sessionsProxy > 0) {
                $row['rpv_proxy_cents'] = intdiv((int) $row['revenue_cents'], $sessionsProxy);
                $row['purchase_rate_ppm'] = (int) floor(((int) $row['purchase_count'] / $sessionsProxy) * 1_000_000);
            }

            unset($row['_sessions_proxy_count']);
        }
        unset($row);

        return [
            'revenue_daily' => array_values($revenue),
            'cluster_daily' => array_values($cluster),
            'ignored_non_backend_purchase_truth_count' => $ignoredNonBackendTruth,
            'excluded_internal_qa_bot_count' => $excluded,
            'purchase_truth_source' => self::BACKEND_PURCHASE_TRUTH_SOURCE,
            'ga4_purchase_truth' => false,
            'baidu_purchase_truth' => false,
        ];
    }

    private function isSuccessfulPurchaseStatus(mixed $status): bool
    {
        return in_array(strtolower((string) $status), ['paid', 'confirmed', 'succeeded', 'success', 'benefit_granted'], true);
    }

    private function reportDate(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return now()->toDateString();
        }

        return substr((string) $value, 0, 10);
    }

    /**
     * @param  list<mixed>  $parts
     */
    private function key(array $parts): string
    {
        return hash('sha256', implode('|', array_map(static fn (mixed $part): string => (string) $part, $parts)));
    }

    private function safeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
