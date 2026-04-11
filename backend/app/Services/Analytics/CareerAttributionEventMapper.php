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
        'career_recommendation_index_view',
        'career_recommendation_detail_view',
        'career_job_search_submit',
        'career_job_search_result_click',
        'career_job_index_result_click',
        'career_job_detail_cta_click',
        'career_recommendation_result_click',
        'career_recommendation_matched_job_click',
        'career_transition_preview_view',
        'career_transition_preview_target_click',
        'career_ready_surface_exposed',
        'career_blocked_surface_exposed',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_ROUTE_FAMILIES = [
        'landing',
        'jobs',
        'jobs_search',
        'job_detail',
        'recommendations',
        'recommendation_detail',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_SUBJECT_KINDS = [
        'none',
        'job_slug',
        'recommendation_type',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_QUERY_MODES = [
        'query',
        'non_query',
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
        $queryMode = $this->normalizeEnum(
            $payload['query_mode'] ?? null,
            self::ALLOWED_QUERY_MODES,
            'query_mode'
        );
        $subjectKey = $this->normalizeSubjectKey($payload['subject_key'] ?? null, $subjectKind);

        $meta = [
            'entry_surface' => $this->normalizeOptionalString($payload['entry_surface'] ?? null, 128) ?? 'unknown',
            'source_page_type' => $this->normalizeOptionalString($payload['source_page_type'] ?? null, 64) ?? 'unknown',
            'target_action' => $this->normalizeOptionalString($payload['target_action'] ?? null, 128),
            'landing_path' => $this->normalizePath($payload['landing_path'] ?? null) ?? $path,
            'route_family' => $routeFamily,
            'subject_kind' => $subjectKind,
            'subject_key' => $subjectKey,
            'query_mode' => $queryMode,
        ];

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
