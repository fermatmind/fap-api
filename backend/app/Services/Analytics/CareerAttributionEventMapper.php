<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Illuminate\Validation\ValidationException;

final class CareerAttributionEventMapper
{
    /**
     * @var list<string>
     */
    public const ALLOWED_EVENT_NAMES = [
        'career_landing_view',
        'career_job_index_view',
        'career_job_detail_view',
        'career_family_hub_view',
        'career_recommendation_index_view',
        'career_recommendation_detail_view',
        'career_job_search_submit',
        'career_job_search_result_click',
        'career_job_index_result_click',
        'career_family_hub_child_click',
        'career_job_detail_cta_click',
        'career_shortlist_add',
        'career_support_link_click',
        'career_recommendation_result_click',
        'career_recommendation_matched_job_click',
        'career_transition_preview_view',
        'career_transition_preview_target_click',
        'career_alias_resolution_submit',
        'career_alias_resolution_target_click',
        'career_alias_resolution_no_result',
        'career_ready_surface_exposed',
        'career_blocked_surface_exposed',
        'career_claim_blocked_surface_exposed',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_ROUTE_FAMILIES = [
        'landing',
        'jobs',
        'jobs_search',
        'job_detail',
        'family_hub',
        'alias_resolution',
        'recommendations',
        'recommendation_detail',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_SUBJECT_KINDS = [
        'none',
        'family_slug',
        'job_slug',
        'recommendation_type',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_SOURCE_PAGE_TYPES = [
        'landing',
        'job_index',
        'job_detail',
        'recommendation_index',
        'recommendation_detail',
        'family_hub',
        'alias_disambiguation',
    ];

    /**
     * @var array<string, string>
     */
    private const SOURCE_PAGE_TYPE_ALIASES = [
        'career_job_index' => 'job_index',
        'career_job_detail' => 'job_detail',
        'career_recommendation_index' => 'recommendation_index',
        'career_recommendation_detail' => 'recommendation_detail',
        'career_family_hub' => 'family_hub',
        'career_alias_disambiguation' => 'alias_disambiguation',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_QUERY_MODES = [
        'query',
        'non_query',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_BLOCKED_CLAIM_KINDS = [
        'salary',
        'strong_claim',
        'ai_strategy',
        'transition_recommendation',
    ];

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{event_code:string,meta:array<string,mixed>,context:array<string,mixed>}
     */
    public function map(array $envelope, int $orgId): array
    {
        $payload = is_array($envelope['payload'] ?? null) ? $envelope['payload'] : [];
        $normalized = EventNormalizer::normalize([
            'event_name' => $envelope['eventName'] ?? null,
            'event_code' => $envelope['eventName'] ?? null,
            'occurred_at' => $envelope['timestamp'] ?? null,
            'anon_id' => $envelope['anonymousId'] ?? null,
            'session_id' => $envelope['sessionId'] ?? null,
            'request_id' => $envelope['requestId'] ?? null,
            'locale' => $payload['locale'] ?? null,
            'meta_json' => $payload,
        ], [
            'occurred_at' => $envelope['timestamp'] ?? null,
            'anon_id' => $envelope['anonymousId'] ?? null,
            'session_id' => $envelope['sessionId'] ?? null,
            'request_id' => $envelope['requestId'] ?? null,
            'locale' => $payload['locale'] ?? null,
        ]);

        $columns = is_array($normalized['columns'] ?? null) ? $normalized['columns'] : [];
        $eventCode = strtolower(trim((string) ($columns['event_name'] ?? '')));
        if (! in_array($eventCode, self::ALLOWED_EVENT_NAMES, true)) {
            throw ValidationException::withMessages([
                'eventName' => 'eventName is not supported by career attribution ingest.',
            ]);
        }

        $path = $this->normalizePath($envelope['path'] ?? null) ?? '/';
        $routeFamily = $this->normalizeEnum(
            $payload['route_family'] ?? null,
            self::ALLOWED_ROUTE_FAMILIES,
            'route_family'
        );
        $subjectKind = $this->normalizeEnum(
            $payload['subject_kind'] ?? null,
            self::ALLOWED_SUBJECT_KINDS,
            'subject_kind'
        );
        $sourcePageType = $this->normalizeSourcePageType($payload['source_page_type'] ?? null);
        $queryMode = $this->normalizeEnum(
            $payload['query_mode'] ?? null,
            self::ALLOWED_QUERY_MODES,
            'query_mode'
        );
        $subjectKey = $this->normalizeSubjectKey($payload['subject_key'] ?? null, $subjectKind);
        $blockedClaimKind = $this->normalizeBlockedClaimKind($eventCode, $payload['blocked_claim_kind'] ?? null);
        $this->validateClaimBlockedEventScope($eventCode, $sourcePageType, $routeFamily, $subjectKind);
        $this->validateConversionEventScope(
            $eventCode,
            $sourcePageType,
            $routeFamily,
            $subjectKind,
            $queryMode,
            (string) ($payload['target_action'] ?? '')
        );

        $meta = [
            'entry_surface' => $this->normalizeOptionalString($payload['entry_surface'] ?? null, 128) ?? 'unknown',
            'source_page_type' => $sourcePageType,
            'target_action' => $this->normalizeOptionalString($payload['target_action'] ?? null, 128),
            'landing_path' => $this->normalizePath($payload['landing_path'] ?? null) ?? $path,
            'route_family' => $routeFamily,
            'subject_kind' => $subjectKind,
            'subject_key' => $subjectKey,
            'query_mode' => $queryMode,
        ];

        if ($blockedClaimKind !== null) {
            $meta['blocked_claim_kind'] = $blockedClaimKind;
        }

        return [
            'event_code' => $eventCode,
            'meta' => $meta,
            'context' => [
                'org_id' => max(0, $orgId),
                'anon_id' => $columns['anon_id'] ?? null,
                'session_id' => $columns['session_id'] ?? null,
                'request_id' => $columns['request_id'] ?? null,
                'channel' => 'web',
                'locale' => $this->normalizeLocale($columns['locale'] ?? null, $path),
                'occurred_at' => $columns['occurred_at'] ?? null,
                'scale_code' => 'CAREER',
                'scale_code_v2' => 'CAREER',
            ],
        ];
    }

    private function validateConversionEventScope(
        string $eventCode,
        string $sourcePageType,
        string $routeFamily,
        string $subjectKind,
        string $queryMode,
        string $targetActionRaw,
    ): void {
        $targetAction = strtolower(trim($targetActionRaw));

        if ($eventCode === 'career_shortlist_add') {
            if (! in_array($sourcePageType, ['job_detail', 'recommendation_detail'], true)) {
                throw ValidationException::withMessages([
                    'payload.source_page_type' => 'payload.source_page_type is not supported for career_shortlist_add.',
                ]);
            }
            if (! in_array($routeFamily, ['job_detail', 'recommendation_detail'], true)) {
                throw ValidationException::withMessages([
                    'payload.route_family' => 'payload.route_family is not supported for career_shortlist_add.',
                ]);
            }
            if (($sourcePageType === 'job_detail' && $routeFamily !== 'job_detail')
                || ($sourcePageType === 'recommendation_detail' && $routeFamily !== 'recommendation_detail')) {
                throw ValidationException::withMessages([
                    'payload.route_family' => 'payload.route_family must align with payload.source_page_type for career_shortlist_add.',
                ]);
            }
            if ($subjectKind !== 'job_slug') {
                throw ValidationException::withMessages([
                    'payload.subject_kind' => 'payload.subject_kind must be job_slug for career_shortlist_add.',
                ]);
            }
            if ($queryMode !== 'non_query') {
                throw ValidationException::withMessages([
                    'payload.query_mode' => 'payload.query_mode must be non_query for career_shortlist_add.',
                ]);
            }
            if ($targetAction !== 'add_shortlist') {
                throw ValidationException::withMessages([
                    'payload.target_action' => 'payload.target_action must be add_shortlist for career_shortlist_add.',
                ]);
            }
        }

        if ($eventCode === 'career_job_detail_cta_click') {
            if ($sourcePageType !== 'job_detail') {
                throw ValidationException::withMessages([
                    'payload.source_page_type' => 'payload.source_page_type must be career_job_detail for career_job_detail_cta_click.',
                ]);
            }
            if ($routeFamily !== 'job_detail') {
                throw ValidationException::withMessages([
                    'payload.route_family' => 'payload.route_family must be job_detail for career_job_detail_cta_click.',
                ]);
            }
            if ($subjectKind !== 'job_slug') {
                throw ValidationException::withMessages([
                    'payload.subject_kind' => 'payload.subject_kind must be job_slug for career_job_detail_cta_click.',
                ]);
            }
            if ($queryMode !== 'non_query') {
                throw ValidationException::withMessages([
                    'payload.query_mode' => 'payload.query_mode must be non_query for career_job_detail_cta_click.',
                ]);
            }
            if (! in_array($targetAction, ['open_next_step_link', 'open_transition_cta', 'open_primary_cta'], true)) {
                throw ValidationException::withMessages([
                    'payload.target_action' => 'payload.target_action is not supported for career_job_detail_cta_click.',
                ]);
            }
        }

        if ($eventCode === 'career_support_link_click') {
            if (! in_array($sourcePageType, ['job_detail', 'recommendation_detail'], true)) {
                throw ValidationException::withMessages([
                    'payload.source_page_type' => 'payload.source_page_type is not supported for career_support_link_click.',
                ]);
            }
            if (! in_array($routeFamily, ['job_detail', 'recommendation_detail'], true)) {
                throw ValidationException::withMessages([
                    'payload.route_family' => 'payload.route_family is not supported for career_support_link_click.',
                ]);
            }
            if (($sourcePageType === 'job_detail' && $routeFamily !== 'job_detail')
                || ($sourcePageType === 'recommendation_detail' && $routeFamily !== 'recommendation_detail')) {
                throw ValidationException::withMessages([
                    'payload.route_family' => 'payload.route_family must align with payload.source_page_type for career_support_link_click.',
                ]);
            }
            if (! in_array($subjectKind, ['job_slug', 'none'], true)) {
                throw ValidationException::withMessages([
                    'payload.subject_kind' => 'payload.subject_kind is not supported for career_support_link_click.',
                ]);
            }
            if ($queryMode !== 'non_query') {
                throw ValidationException::withMessages([
                    'payload.query_mode' => 'payload.query_mode must be non_query for career_support_link_click.',
                ]);
            }
            if ($targetAction !== 'open_support_link') {
                throw ValidationException::withMessages([
                    'payload.target_action' => 'payload.target_action must be open_support_link for career_support_link_click.',
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeEnum(mixed $value, array $allowed, string $field): string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            throw ValidationException::withMessages([
                "payload.$field" => "payload.$field is required.",
            ]);
        }

        if (! in_array($normalized, $allowed, true)) {
            throw ValidationException::withMessages([
                "payload.$field" => "payload.$field is not supported by career attribution ingest.",
            ]);
        }

        return $normalized;
    }

    private function normalizeBlockedClaimKind(string $eventCode, mixed $value): ?string
    {
        $normalized = strtolower((string) $this->normalizeOptionalString($value, 64));
        if ($eventCode !== 'career_claim_blocked_surface_exposed') {
            if ($normalized === '') {
                return null;
            }

            throw ValidationException::withMessages([
                'payload.blocked_claim_kind' => 'payload.blocked_claim_kind is only allowed for career_claim_blocked_surface_exposed.',
            ]);
        }

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'payload.blocked_claim_kind' => 'payload.blocked_claim_kind is required for career_claim_blocked_surface_exposed.',
            ]);
        }

        if (! in_array($normalized, self::ALLOWED_BLOCKED_CLAIM_KINDS, true)) {
            throw ValidationException::withMessages([
                'payload.blocked_claim_kind' => 'payload.blocked_claim_kind is not supported by career attribution ingest.',
            ]);
        }

        return $normalized;
    }

    private function validateClaimBlockedEventScope(
        string $eventCode,
        string $sourcePageType,
        string $routeFamily,
        string $subjectKind,
    ): void {
        if ($eventCode !== 'career_claim_blocked_surface_exposed') {
            return;
        }

        if (! in_array($sourcePageType, ['job_detail', 'recommendation_detail'], true)) {
            throw ValidationException::withMessages([
                'payload.source_page_type' => 'payload.source_page_type is not supported for career_claim_blocked_surface_exposed.',
            ]);
        }

        if (! in_array($routeFamily, ['job_detail', 'recommendation_detail'], true)) {
            throw ValidationException::withMessages([
                'payload.route_family' => 'payload.route_family is not supported for career_claim_blocked_surface_exposed.',
            ]);
        }

        if (($sourcePageType === 'job_detail' && $routeFamily !== 'job_detail')
            || ($sourcePageType === 'recommendation_detail' && $routeFamily !== 'recommendation_detail')) {
            throw ValidationException::withMessages([
                'payload.route_family' => 'payload.route_family must align with payload.source_page_type for career_claim_blocked_surface_exposed.',
            ]);
        }

        if (! in_array($subjectKind, ['job_slug', 'none'], true)) {
            throw ValidationException::withMessages([
                'payload.subject_kind' => 'payload.subject_kind is not supported for career_claim_blocked_surface_exposed.',
            ]);
        }
    }

    private function normalizeSubjectKey(mixed $value, string $subjectKind): string
    {
        if ($subjectKind === 'none') {
            return '';
        }

        $normalized = $this->normalizeOptionalString($value, 128);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'payload.subject_key' => 'payload.subject_key is required when subject_kind is not none.',
            ]);
        }

        return $normalized;
    }

    private function normalizeSourcePageType(mixed $value): string
    {
        $normalized = strtolower((string) $this->normalizeOptionalString($value, 64));
        if ($normalized === '') {
            return 'unknown';
        }

        $normalized = self::SOURCE_PAGE_TYPE_ALIASES[$normalized] ?? $normalized;

        if (! in_array($normalized, self::ALLOWED_SOURCE_PAGE_TYPES, true)) {
            throw ValidationException::withMessages([
                'payload.source_page_type' => 'payload.source_page_type is not supported by career attribution ingest.',
            ]);
        }

        return $normalized;
    }

    private function normalizeLocale(mixed $value, string $path): string
    {
        $normalized = strtolower((string) $this->normalizeOptionalString($value, 16));
        if ($normalized !== '') {
            return $normalized;
        }

        return str_starts_with($path, '/zh') ? 'zh' : 'en';
    }

    private function normalizePath(mixed $value): ?string
    {
        $normalized = $this->normalizeOptionalString($value, 2048);
        if ($normalized === null) {
            return null;
        }

        return str_starts_with($normalized, '/') ? $normalized : '/'.$normalized;
    }

    private function normalizeOptionalString(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized, 'UTF-8') > $maxLength) {
            $normalized = mb_substr($normalized, 0, $maxLength, 'UTF-8');
        }

        return $normalized;
    }
}
