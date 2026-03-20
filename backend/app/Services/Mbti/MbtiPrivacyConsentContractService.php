<?php

declare(strict_types=1);

namespace App\Services\Mbti;

final class MbtiPrivacyConsentContractService
{
    private const VERSION = 'mbti.privacy_contract.v1';
    private const SUBJECT_EXPORT_SCHEMA = 'mbti.subject_export.v1';
    private const ANONYMIZED_VECTOR_SCHEMA = 'mbti.anonymized_vector.v1';
    private const ERASURE_SCOPE_SCHEMA = 'mbti.erasure_scope.v1';

    /**
     * @var list<string>
     */
    private const EXPORTABLE_CANONICAL_ASSET_PATHS = [
        'result.type_code',
        'result.scores_json',
        'result.scores_pct',
        'result.axis_states',
        'report.summary',
        'report.profile',
        'report.identity_card',
        'report.layers',
        'report.sections',
        'report.highlights',
        'report.recommended_reads',
        'mbti_public_summary_v1',
        'mbti_public_projection_v1',
    ];

    /**
     * @var list<string>
     */
    private const EXPORTABLE_DERIVED_PERSONALIZATION_FIELDS = [
        'type_code',
        'identity',
        'axis_vector',
        'axis_bands',
        'boundary_flags',
        'dominant_axes',
        'scene_fingerprint',
        'explainability_summary',
        'close_call_axes',
        'neighbor_type_keys',
        'contrast_keys',
        'confidence_or_stability_keys',
        'work_style_keys',
        'relationship_style_keys',
        'decision_style_keys',
        'stress_recovery_keys',
        'communication_style_keys',
        'work_style_summary',
        'role_fit_keys',
        'collaboration_fit_keys',
        'work_env_preference_keys',
        'career_next_step_keys',
        'action_plan_summary',
        'weekly_action_keys',
        'relationship_action_keys',
        'work_experiment_keys',
        'watchout_keys',
        'ordered_recommendation_keys',
        'ordered_action_keys',
        'recommendation_priority_keys',
        'action_priority_keys',
        'reading_focus_key',
        'action_focus_key',
        'user_state',
        'orchestration',
        'continuity',
        'variant_keys',
        'pack_id',
        'engine_version',
        'dynamic_sections_version',
    ];

    /**
     * @var list<string>
     */
    private const EXCLUDED_META_PATHS = [
        'events.meta_json',
        'events.experiments_json',
        'request_id',
        'session_id',
        'share_id',
        'share_click_id',
        'order_no',
        'user_id',
        'anon_id',
        'email',
        'phone',
        'authorization',
        'token',
    ];

    /**
     * @var list<string>
     */
    private const ANONYMIZED_VECTOR_ALLOWED_FIELDS = [
        'canonical_type_code',
        'identity',
        'axis_bands',
        'boundary_flags',
        'dominant_axes',
        'scene_fingerprint',
        'close_call_axes',
        'confidence_or_stability_keys',
        'ordered_recommendation_keys',
        'ordered_action_keys',
        'recommendation_priority_keys',
        'action_priority_keys',
        'reading_focus_key',
        'action_focus_key',
        'pack_id',
        'engine_version',
        'dynamic_sections_version',
        'locale',
        'region',
    ];

    /**
     * @var list<string>
     */
    private const DIRECT_IDENTIFIER_FIELDS = [
        'user_id',
        'anon_id',
        'attempt_id',
        'share_id',
        'share_click_id',
        'request_id',
        'session_id',
        'email',
        'phone',
        'order_no',
    ];

    /**
     * @var list<string>
     */
    private const ATTEMPT_ERASURE_OBJECTS = [
        'attempts',
        'results',
        'report_snapshots',
        'shares',
        'report_jobs',
        'attempt_answer_sets',
        'attempt_answer_rows',
    ];

    /**
     * @var list<string>
     */
    private const SUBJECT_ERASURE_OBJECTS = [
        'auth_tokens',
        'fm_tokens',
        'email_outbox',
        'identities',
        'sessions',
        'users',
        'events',
        'orders',
        'payment_events',
        'benefit_grants',
    ];

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function attachContract(array $personalization, array $context = []): array
    {
        if ($personalization === []) {
            return [];
        }

        $personalization['privacy_contract_v1'] = $this->buildContract($personalization, $context);

        return $personalization;
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildContract(array $personalization, array $context = []): array
    {
        $publicSafe = (bool) ($context['public_safe'] ?? false);
        $region = $this->normalizeText($context['region'] ?? config('regions.default_region', 'CN_MAINLAND'));
        $locale = $this->normalizeText($context['locale'] ?? data_get($personalization, 'locale', 'zh-CN'));
        $privacyPolicyVersion = $this->normalizeText(
            data_get(config('regions.regions'), "{$region}.policy_versions.privacy", '2026-01-01')
        );
        $privacyPolicyUrl = $this->normalizeText(
            data_get(config('regions.regions'), "{$region}.legal_urls.privacy", config('app.url'))
        );

        return [
            'version' => self::VERSION,
            'policy' => [
                'region' => $region,
                'locale' => $locale,
                'privacy_policy_version' => $privacyPolicyVersion,
                'privacy_policy_url' => $privacyPolicyUrl,
            ],
            'consent_scope' => [
                'service_delivery' => true,
                'subject_export' => ! $publicSafe,
                'telemetry_product_improvement' => ! $publicSafe,
                'experimentation_pseudonymous' => ! $publicSafe,
                'norming_anonymized_only' => ! $publicSafe,
                'public_share_summary' => true,
                'erasure_request_ready' => true,
            ],
            'exportable_assets' => [
                'subject_bundle_schema' => self::SUBJECT_EXPORT_SCHEMA,
                'subject_bundle_available' => ! $publicSafe,
                'canonical_result_paths' => self::EXPORTABLE_CANONICAL_ASSET_PATHS,
                'derived_personalization_fields' => self::EXPORTABLE_DERIVED_PERSONALIZATION_FIELDS,
                'public_safe_paths' => [
                    'mbti_public_summary_v1',
                    'mbti_public_projection_v1',
                    'mbti_continuity_v1',
                    'mbti_read_contract_v1',
                    'mbti_privacy_contract_v1',
                ],
                'excluded_meta_paths' => self::EXCLUDED_META_PATHS,
            ],
            'anonymized_vector_contract' => [
                'schema' => self::ANONYMIZED_VECTOR_SCHEMA,
                'available' => ! $publicSafe,
                'allowed_fields' => self::ANONYMIZED_VECTOR_ALLOWED_FIELDS,
                'forbidden_direct_identifiers' => self::DIRECT_IDENTIFIER_FIELDS,
                'requires_consent_scope' => 'norming_anonymized_only',
            ],
            'erasure_scope' => [
                'schema' => self::ERASURE_SCOPE_SCHEMA,
                'supports_dry_run' => true,
                'attempt_objects' => self::ATTEMPT_ERASURE_OBJECTS,
                'subject_objects' => self::SUBJECT_ERASURE_OBJECTS,
                'execution_services' => [
                    'attempt' => \App\Services\Attempts\AttemptDataLifecycleService::class,
                    'subject' => \App\Services\Attempts\UserDataLifecycleService::class,
                ],
                'downstream_candidates' => [
                    'share_public_records',
                    'future_norming_candidate_vectors',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $resultPayload
     * @param  array<string, mixed>  $reportEnvelope
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildSubjectExportBundle(
        array $resultPayload,
        array $reportEnvelope,
        array $personalization,
        array $context = []
    ): array {
        $contract = $this->buildContract($personalization, $context);

        return [
            'schema' => self::SUBJECT_EXPORT_SCHEMA,
            'privacy_contract_version' => self::VERSION,
            'policy' => $contract['policy'],
            'subject_ref' => [
                'attempt_id' => $this->normalizeText($context['attempt_id'] ?? ''),
                'scale_code' => 'MBTI',
                'locale' => $this->normalizeText($context['locale'] ?? data_get($personalization, 'locale', '')),
                'region' => $this->normalizeText($context['region'] ?? config('regions.default_region', 'CN_MAINLAND')),
            ],
            'canonical_assets' => [
                'type_code' => $this->normalizeText($resultPayload['type_code'] ?? data_get($personalization, 'type_code', '')),
                'scores' => is_array($resultPayload['scores_json'] ?? null) ? $resultPayload['scores_json'] : [],
                'scores_pct' => is_array($resultPayload['scores_pct'] ?? null) ? $resultPayload['scores_pct'] : [],
                'axis_states' => is_array($resultPayload['axis_states'] ?? null) ? $resultPayload['axis_states'] : [],
                'report' => is_array($reportEnvelope['report'] ?? null) ? $reportEnvelope['report'] : [],
            ],
            'derived_assets' => [
                'personalization' => $this->pickFields($personalization, self::EXPORTABLE_DERIVED_PERSONALIZATION_FIELDS),
                'mbti_public_summary_v1' => is_array($reportEnvelope['mbti_public_summary_v1'] ?? null)
                    ? $reportEnvelope['mbti_public_summary_v1']
                    : [],
                'mbti_public_projection_v1' => is_array($reportEnvelope['mbti_public_projection_v1'] ?? null)
                    ? $reportEnvelope['mbti_public_projection_v1']
                    : [],
            ],
            'excluded_meta_paths' => self::EXCLUDED_META_PATHS,
        ];
    }

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildAnonymizedVectorBundle(array $personalization, array $context = []): array
    {
        $contract = $this->buildContract($personalization, $context);

        return [
            'schema' => self::ANONYMIZED_VECTOR_SCHEMA,
            'privacy_contract_version' => self::VERSION,
            'consent_scope' => [
                'norming_anonymized_only' => $this->allowsNormingAnonymized($contract),
            ],
            'vector' => [
                'canonical_type_code' => $this->canonicalTypeCode((string) ($personalization['type_code'] ?? '')),
                'identity' => $this->normalizeText($personalization['identity'] ?? ''),
                'axis_bands' => is_array($personalization['axis_bands'] ?? null) ? $personalization['axis_bands'] : [],
                'boundary_flags' => is_array($personalization['boundary_flags'] ?? null) ? $personalization['boundary_flags'] : [],
                'dominant_axes' => $this->normalizeDominantAxes($personalization['dominant_axes'] ?? []),
                'scene_fingerprint' => $this->normalizeSceneFingerprint($personalization['scene_fingerprint'] ?? []),
                'close_call_axes' => $this->normalizeCloseCallAxes($personalization['close_call_axes'] ?? []),
                'confidence_or_stability_keys' => array_values((array) ($personalization['confidence_or_stability_keys'] ?? [])),
                'ordered_recommendation_keys' => array_values((array) ($personalization['ordered_recommendation_keys'] ?? [])),
                'ordered_action_keys' => array_values((array) ($personalization['ordered_action_keys'] ?? [])),
                'recommendation_priority_keys' => array_values((array) ($personalization['recommendation_priority_keys'] ?? [])),
                'action_priority_keys' => array_values((array) ($personalization['action_priority_keys'] ?? [])),
                'reading_focus_key' => $this->normalizeText($personalization['reading_focus_key'] ?? ''),
                'action_focus_key' => $this->normalizeText($personalization['action_focus_key'] ?? ''),
                'pack_id' => $this->normalizeText($personalization['pack_id'] ?? ''),
                'engine_version' => $this->normalizeText($personalization['engine_version'] ?? ''),
                'dynamic_sections_version' => $this->normalizeText($personalization['dynamic_sections_version'] ?? ''),
                'locale' => $this->normalizeText($context['locale'] ?? $personalization['locale'] ?? ''),
                'region' => $this->normalizeText($context['region'] ?? config('regions.default_region', 'CN_MAINLAND')),
            ],
            'forbidden_direct_identifiers' => self::DIRECT_IDENTIFIER_FIELDS,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function buildErasureScopeManifest(array $context = []): array
    {
        return [
            'schema' => self::ERASURE_SCOPE_SCHEMA,
            'mode' => $this->normalizeErasureMode((string) ($context['mode'] ?? 'hybrid_anonymize')),
            'subject_ref' => [
                'attempt_id' => $this->normalizeText($context['attempt_id'] ?? ''),
                'has_user_subject' => (bool) ($context['has_user_subject'] ?? false),
                'has_anon_subject' => (bool) ($context['has_anon_subject'] ?? false),
            ],
            'attempt_objects' => self::ATTEMPT_ERASURE_OBJECTS,
            'subject_objects' => self::SUBJECT_ERASURE_OBJECTS,
            'share_public_records' => [
                'shares',
                'events:share_result',
                'events:share_click',
            ],
            'future_norming_candidates' => [
                'anonymized_vector_exports',
            ],
            'execution_services' => [
                'attempt' => \App\Services\Attempts\AttemptDataLifecycleService::class,
                'subject' => \App\Services\Attempts\UserDataLifecycleService::class,
            ],
            'supports_dry_run' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    public function allowsTelemetryProductImprovement(array $contract): bool
    {
        return (bool) data_get($contract, 'consent_scope.telemetry_product_improvement', false);
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    public function allowsExperimentation(array $contract): bool
    {
        return (bool) data_get($contract, 'consent_scope.experimentation_pseudonymous', false);
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    public function allowsNormingAnonymized(array $contract): bool
    {
        return (bool) data_get($contract, 'consent_scope.norming_anonymized_only', false);
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    public function buildTelemetryConsentMeta(array $contract): array
    {
        return [
            'privacy_contract_version' => $this->normalizeText($contract['version'] ?? ''),
            'consent_scope' => is_array($contract['consent_scope'] ?? null) ? $contract['consent_scope'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function pickFields(array $source, array $fields): array
    {
        $picked = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $source)) {
                continue;
            }

            $picked[$field] = $source[$field];
        }

        return $picked;
    }

    /**
     * @param  mixed  $value
     * @return list<array{axis:string,side:string,percent:float|int,state:string}>
     */
    private function normalizeDominantAxes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $normalized[] = [
                'axis' => $this->normalizeText($entry['axis'] ?? ''),
                'side' => $this->normalizeText($entry['side'] ?? ''),
                'percent' => is_numeric($entry['percent'] ?? null) ? 0 + $entry['percent'] : 0,
                'state' => $this->normalizeText($entry['state'] ?? ''),
            ];
        }

        return $normalized;
    }

    /**
     * @param  mixed  $value
     * @return array<string, string>
     */
    private function normalizeSceneFingerprint(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $scene => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $styleKey = $this->normalizeText($entry['style_key'] ?? $entry['styleKey'] ?? '');
            if ($styleKey === '') {
                continue;
            }

            $normalized[(string) $scene] = $styleKey;
        }

        return $normalized;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function normalizeCloseCallAxes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $axes = [];
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $axis = $this->normalizeText($entry['axis'] ?? '');
            if ($axis !== '') {
                $axes[] = $axis;
            }
        }

        return array_values(array_unique($axes));
    }

    private function canonicalTypeCode(string $typeCode): string
    {
        $normalized = strtoupper(trim($typeCode));
        if ($normalized === '') {
            return '';
        }

        return preg_replace('/-(A|T)$/', '', $normalized) ?? $normalized;
    }

    private function normalizeErasureMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        return in_array($normalized, ['delete', 'hybrid_anonymize'], true)
            ? $normalized
            : 'hybrid_anonymize';
    }

    private function normalizeText(mixed $value): string
    {
        return trim((string) $value);
    }
}
