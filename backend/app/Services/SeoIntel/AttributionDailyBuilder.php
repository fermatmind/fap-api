<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class AttributionDailyBuilder
{
    private const FUNNEL_EVENTS = [
        'start_attempt',
        'submit_attempt',
        'view_result',
        'click_unlock',
        'create_order',
        'payment_confirmed',
        'purchase_success',
    ];

    public function __construct(
        private readonly SourceEngineNormalizer $sourceEngineNormalizer,
        private readonly ConsentStateNormalizer $consentStateNormalizer,
        private readonly InternalTrafficFilter $internalTrafficFilter,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $events, array $options = []): array
    {
        $includeInternal = (bool) ($options['include_internal'] ?? false);
        $funnel = [];
        $landing = [];
        $cluster = [];
        $consent = [];
        $excluded = 0;

        foreach ($events as $event) {
            if ($this->internalTrafficFilter->shouldExclude($event, $includeInternal)) {
                $excluded++;

                continue;
            }

            $eventName = $this->canonicalEventName((string) ($event['event_name'] ?? ''));
            $sourceEngine = $this->sourceEngineNormalizer->normalizeFromPayload($event);
            $consentState = $this->consentStateNormalizer->normalize($event['consent_state'] ?? null);
            $date = $this->reportDate($event['occurred_at'] ?? null);
            $trafficQuality = $this->safeString($event['traffic_quality'] ?? 'production_user', 'unknown');
            $environment = $this->safeNullableString($event['environment'] ?? 'production');
            $clusterValue = $this->safeNullableString($event['cluster'] ?? null);

            $funnelKey = $this->key([
                $date,
                $event['canonical_url_hash'] ?? '',
                $event['locale'] ?? '',
                $event['page_entity_type'] ?? '',
                $event['entity_id_or_slug'] ?? '',
                $clusterValue ?? '',
                $sourceEngine,
                $consentState,
                $trafficQuality,
                $environment ?? '',
            ]);

            $funnel[$funnelKey] ??= [
                'report_date' => $date,
                'canonical_url_hash' => $this->safeNullableString($event['canonical_url_hash'] ?? null),
                'locale' => $this->safeNullableString($event['locale'] ?? null),
                'page_entity_type' => $this->safeNullableString($event['page_entity_type'] ?? null),
                'entity_id_or_slug' => $this->safeNullableString($event['entity_id_or_slug'] ?? null),
                'cluster' => $clusterValue,
                'source_engine' => $sourceEngine,
                'consent_state' => $consentState,
                'traffic_quality' => $trafficQuality,
                'environment' => $environment,
                'start_attempt_count' => 0,
                'submit_attempt_count' => 0,
                'view_result_count' => 0,
                'click_unlock_count' => 0,
                'create_order_count' => 0,
                'payment_confirmed_count' => 0,
                'purchase_success_count' => 0,
            ];

            if (in_array($eventName, self::FUNNEL_EVENTS, true)) {
                $funnel[$funnelKey][$eventName.'_count']++;
            }

            $landingKey = $this->key([
                $date,
                $event['canonical_url_hash'] ?? '',
                $event['locale'] ?? '',
                $sourceEngine,
                $event['source_route_family'] ?? '',
                $event['source_slug'] ?? '',
                $event['content_id'] ?? '',
                $event['test_slug'] ?? '',
                $event['cta_id'] ?? '',
                $event['entrypoint'] ?? '',
            ]);

            $landing[$landingKey] ??= [
                'report_date' => $date,
                'canonical_url_hash' => $this->safeNullableString($event['canonical_url_hash'] ?? null),
                'locale' => $this->safeNullableString($event['locale'] ?? null),
                'source_engine' => $sourceEngine,
                'source_route_family' => $this->safeNullableString($event['source_route_family'] ?? null),
                'source_slug' => $this->safeNullableString($event['source_slug'] ?? null),
                'content_id' => $this->safeNullableString($event['content_id'] ?? null),
                'test_slug' => $this->safeNullableString($event['test_slug'] ?? null),
                'cta_id' => $this->safeNullableString($event['cta_id'] ?? null),
                'entrypoint' => $this->safeNullableString($event['entrypoint'] ?? null),
                'first_touch_count' => 0,
                'last_touch_count' => 0,
                'cta_touch_count' => 0,
                'landing_event_count' => 0,
                'start_attempt_count' => 0,
                'submit_attempt_count' => 0,
                'purchase_success_count' => 0,
            ];

            $touchType = strtolower((string) ($event['touch_type'] ?? ''));
            if ($touchType === 'first') {
                $landing[$landingKey]['first_touch_count']++;
            }
            if ($touchType === 'last') {
                $landing[$landingKey]['last_touch_count']++;
            }
            if ($touchType === 'cta') {
                $landing[$landingKey]['cta_touch_count']++;
            }
            if ((bool) ($event['is_landing_event'] ?? false)) {
                $landing[$landingKey]['landing_event_count']++;
            }
            if (in_array($eventName, ['start_attempt', 'submit_attempt', 'purchase_success'], true)) {
                $landing[$landingKey][$eventName.'_count']++;
            }

            if ($clusterValue !== null && $clusterValue !== '') {
                $clusterKey = $this->key([$date, $clusterValue, $event['locale'] ?? '', $sourceEngine]);
                $cluster[$clusterKey] ??= [
                    'report_date' => $date,
                    'cluster' => $clusterValue,
                    'locale' => $this->safeNullableString($event['locale'] ?? null),
                    'source_engine' => $sourceEngine,
                    'landing_event_count' => 0,
                    'start_attempt_count' => 0,
                    'submit_attempt_count' => 0,
                    'purchase_count' => 0,
                    'revenue_cents' => 0,
                    'currency' => 'CNY',
                ];

                if ((bool) ($event['is_landing_event'] ?? false)) {
                    $cluster[$clusterKey]['landing_event_count']++;
                }
                if ($eventName === 'start_attempt') {
                    $cluster[$clusterKey]['start_attempt_count']++;
                }
                if ($eventName === 'submit_attempt') {
                    $cluster[$clusterKey]['submit_attempt_count']++;
                }
                if ($eventName === 'purchase_success') {
                    $cluster[$clusterKey]['purchase_count']++;
                }
            }

            $consentKey = $this->key([$date, $consentState, $sourceEngine]);
            $consent[$consentKey] ??= [
                'report_date' => $date,
                'consent_state' => $consentState,
                'source_engine' => $sourceEngine,
                'event_count' => 0,
            ];
            $consent[$consentKey]['event_count']++;
        }

        return [
            'event_funnel_daily' => array_values($funnel),
            'landing_attribution_daily' => array_values($landing),
            'cluster_daily' => array_values($cluster),
            'consent_daily' => array_values($consent),
            'excluded_internal_qa_bot_count' => $excluded,
            'keyword_purchase_attribution_allowed' => false,
        ];
    }

    private function canonicalEventName(string $eventName): string
    {
        return $eventName === 'pay_success' ? 'purchase_success' : $eventName;
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

    private function safeString(mixed $value, string $fallback): string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? $fallback : $value;
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
