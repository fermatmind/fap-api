<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets\Agent;

final class EnneagramResultPageAgentBatchRunner
{
    public const SCHEMA_VERSION = 'fap.enneagram.result_page.agent_batch_runner.v0.1';

    public const PAYLOAD_SCHEMA_VERSION = 'fap.enneagram.result_page.agent_payload.v0.1';

    /**
     * @var array<string,string>
     */
    private const FORBIDDEN_FIELD_FAMILY_BY_FIELD = [
        'attempt_id' => 'private_result_identifier_path_or_vector_leakage',
        'attempt_uuid' => 'private_result_identifier_path_or_vector_leakage',
        'private_url' => 'private_result_identifier_path_or_vector_leakage',
        'private_path' => 'private_result_identifier_path_or_vector_leakage',
        'raw_score' => 'private_result_identifier_path_or_vector_leakage',
        'raw_scores' => 'private_result_identifier_path_or_vector_leakage',
        'raw_score_vector' => 'private_result_identifier_path_or_vector_leakage',
        'domain_vector' => 'private_result_identifier_path_or_vector_leakage',
        'facet_vector' => 'private_result_identifier_path_or_vector_leakage',
        'editor_notes' => 'private_result_identifier_path_or_vector_leakage',
        'qa_notes' => 'private_result_identifier_path_or_vector_leakage',
        'source_selection_notes' => 'private_result_identifier_path_or_vector_leakage',
        'internal_metadata' => 'private_result_identifier_path_or_vector_leakage',
    ];

    /**
     * @var array<string,string>
     */
    private const FORBIDDEN_TERM_FAMILY_BY_TERM = [
        'diagnosis' => 'diagnosis_clinical_treatment',
        'clinical' => 'diagnosis_clinical_treatment',
        'therapy' => 'diagnosis_clinical_treatment',
        'treatment' => 'diagnosis_clinical_treatment',
        'hiring' => 'hiring_employment_screening',
        'employment screening' => 'hiring_employment_screening',
        'you are this type' => 'final_typing_you_are_this_type',
        'you are type' => 'final_typing_you_are_this_type',
        'fixed type' => 'fixed_type_certainty',
        'compare e105 and fc144 scores' => 'e105_fc144_score_comparison',
        'e105 score is higher than fc144' => 'e105_fc144_score_comparison',
        'fc144 is more accurate' => 'fc144_more_accurate_or_replacement_result',
        'fc144 replaces' => 'fc144_more_accurate_or_replacement_result',
        'salary prediction' => 'success_salary_performance_prediction',
        'performance prediction' => 'success_salary_performance_prediction',
        'success prediction' => 'success_salary_performance_prediction',
        '诊断' => 'diagnosis_clinical_treatment',
        '治疗' => 'diagnosis_clinical_treatment',
        '招聘' => 'hiring_employment_screening',
        '雇佣筛选' => 'hiring_employment_screening',
        '你就是这个类型' => 'final_typing_you_are_this_type',
        '固定类型' => 'fixed_type_certainty',
        '分数直接比较' => 'e105_fc144_score_comparison',
        'fc144 更准确' => 'fc144_more_accurate_or_replacement_result',
        '成功预测' => 'success_salary_performance_prediction',
        '薪资预测' => 'success_salary_performance_prediction',
    ];

    /**
     * @param  array{
     *   source_ledger_row?:array<string,mixed>,
     *   target_module?:array<string,mixed>,
     *   public_payload?:array<string,mixed>,
     *   forbidden_claim_policy?:array<string,mixed>,
     *   previous_payload?:array<string,mixed>|null
     * }  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $sourceLedgerRow = (array) ($input['source_ledger_row'] ?? []);
        $targetModule = (array) ($input['target_module'] ?? []);
        $publicPayload = (array) ($input['public_payload'] ?? []);
        $previousPayload = is_array($input['previous_payload'] ?? null) ? (array) $input['previous_payload'] : null;

        $errors = $this->inputErrors($sourceLedgerRow, $targetModule, $publicPayload);
        $payload = $this->payload($sourceLedgerRow, $targetModule, $publicPayload);
        $payloadHash = $this->hash($payload);
        $metadataHits = $this->scanFields($publicPayload);
        $forbiddenClaimHits = $this->scanTerms($publicPayload);
        $fc144Hits = $this->scanFc144Boundary($targetModule, $publicPayload);
        $safetyErrorCount = count($metadataHits) + count($forbiddenClaimHits) + count($fc144Hits);

        if ($safetyErrorCount > 0) {
            $errors[] = 'safety_scan_failed';
        }

        $sourceMappingReport = $this->sourceMappingReport($payload, $sourceLedgerRow);
        $safetyReport = $this->safetyReport($metadataHits, $forbiddenClaimHits, $fc144Hits, $input['forbidden_claim_policy'] ?? []);
        $diffReport = $this->diffReport($previousPayload, $payload, $payloadHash);
        $rollbackReport = $this->rollbackReport($payload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $errors === [],
            'status' => $errors === [] ? 'success' : 'blocked',
            'errors' => array_values(array_unique($errors)),
            'input_contract' => [
                'requires_source_ledger_row' => true,
                'requires_target_module' => true,
                'requires_public_payload' => true,
                'requires_forbidden_claim_policy' => true,
            ],
            'payload' => $payload,
            'payload_hash_sha256' => $payloadHash,
            'source_mapping_report' => $sourceMappingReport,
            'safety_report' => $safetyReport,
            'diff_report' => $diffReport,
            'rollback_report' => $rollbackReport,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string,mixed>  $sourceLedgerRow
     * @param  array<string,mixed>  $targetModule
     * @param  array<string,mixed>  $publicPayload
     * @return list<string>
     */
    private function inputErrors(array $sourceLedgerRow, array $targetModule, array $publicPayload): array
    {
        $errors = [];

        if ((string) ($sourceLedgerRow['source_id'] ?? '') === '') {
            $errors[] = 'missing_source_ledger_row';
        }
        if ((string) ($sourceLedgerRow['source_label'] ?? '') === '') {
            $errors[] = 'missing_source_label';
        }
        if (! is_array($sourceLedgerRow['copy_policy'] ?? null)) {
            $errors[] = 'missing_copy_policy';
        }
        if ((string) ($targetModule['module_key'] ?? '') === '') {
            $errors[] = 'missing_target_module_key';
        }
        if ((string) ($targetModule['result_type'] ?? '') === '') {
            $errors[] = 'missing_target_result_type';
        }
        if ((string) ($targetModule['scope'] ?? '') === '') {
            $errors[] = 'missing_target_scope';
        }
        if ($publicPayload === []) {
            $errors[] = 'missing_public_payload';
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $sourceLedgerRow
     * @param  array<string,mixed>  $targetModule
     * @param  array<string,mixed>  $publicPayload
     * @return array<string,mixed>
     */
    private function payload(array $sourceLedgerRow, array $targetModule, array $publicPayload): array
    {
        $moduleKey = (string) ($targetModule['module_key'] ?? 'unknown_module');
        $resultType = (string) ($targetModule['result_type'] ?? 'unknown_type');
        $scope = (string) ($targetModule['scope'] ?? 'unknown_scope');
        $sourceId = (string) ($sourceLedgerRow['source_id'] ?? 'unknown_source');
        $additionalSourceIds = array_values(array_map(
            static fn ($sourceId): string => (string) $sourceId,
            (array) ($targetModule['additional_source_ids'] ?? [])
        ));
        $sourceIds = array_values(array_unique(array_filter(array_merge([$sourceId], $additionalSourceIds))));

        return [
            'schema_version' => self::PAYLOAD_SCHEMA_VERSION,
            'asset_key' => $this->assetKey($resultType, $moduleKey, $scope, $sourceId),
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'result_type' => $resultType,
            'module_key' => $moduleKey,
            'scope' => $scope,
            'public_payload' => $publicPayload,
            'source_trace' => [
                'source_ids' => $sourceIds,
                'primary_source_id' => $sourceId,
                'source_label' => (string) ($sourceLedgerRow['source_label'] ?? ''),
                'copy_allowed' => (bool) data_get($sourceLedgerRow, 'copy_policy.copy_allowed', false),
                'claim_trace' => [
                    [
                        'claim_key' => $moduleKey.'_source_trace',
                        'source_id' => $sourceId,
                        'limitation' => (string) data_get($sourceLedgerRow, 'limitations.0', 'Pilot/eval harness trace only.'),
                    ],
                ],
            ],
            'safety_flags' => [
                'metadata_leakage_checked' => true,
                'forbidden_claim_checked' => true,
                'fc144_boundary_checked' => true,
                'staging_only' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $sourceLedgerRow
     * @return array<string,mixed>
     */
    private function sourceMappingReport(array $payload, array $sourceLedgerRow): array
    {
        return [
            'schema_version' => 'fap.enneagram.result_page.agent_source_mapping_report.v0.1',
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'source_mapping_failure_count' => (string) ($sourceLedgerRow['source_id'] ?? '') === '' ? 1 : 0,
            'fallback_source_count' => 0,
            'blocked_source_count' => 0,
            'duplicate_selection_count' => 0,
            'branch_provenance_mismatch_count' => 0,
            'mappings' => [
                [
                    'asset_key' => (string) ($payload['asset_key'] ?? ''),
                    'source_ids' => (array) data_get($payload, 'source_trace.source_ids', []),
                    'trace_status' => (string) ($sourceLedgerRow['source_id'] ?? '') === '' ? 'missing_source' : 'mapped',
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string,string>>  $metadataHits
     * @param  list<array<string,string>>  $forbiddenClaimHits
     * @param  list<array<string,string>>  $fc144Hits
     * @return array<string,mixed>
     */
    private function safetyReport(array $metadataHits, array $forbiddenClaimHits, array $fc144Hits, mixed $policy): array
    {
        return [
            'schema_version' => 'fap.enneagram.result_page.agent_safety_report.v0.1',
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'metadata_leakage_hit_count' => count($metadataHits),
            'forbidden_claim_hit_count' => count($forbiddenClaimHits),
            'fc144_boundary_violation_count' => count($fc144Hits),
            'legacy_residual_count' => 0,
            'policy_family_count' => is_array($policy) ? count((array) ($policy['families'] ?? [])) : 0,
            'hits' => [
                'metadata_leakage' => $metadataHits,
                'forbidden_claims' => $forbiddenClaimHits,
                'fc144_boundary' => $fc144Hits,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $previousPayload
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function diffReport(?array $previousPayload, array $payload, string $payloadHash): array
    {
        return [
            'schema_version' => 'fap.enneagram.result_page.agent_diff_report.v0.1',
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'previous_payload_provided' => $previousPayload !== null,
            'previous_payload_hash_sha256' => $previousPayload === null ? null : $this->hash($previousPayload),
            'next_payload_hash_sha256' => $payloadHash,
            'changed' => $previousPayload === null || $this->hash($previousPayload) !== $payloadHash,
            'changed_fields' => $previousPayload === null ? ['payload_created'] : $this->changedFields($previousPayload, $payload),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function rollbackReport(array $payload): array
    {
        return [
            'schema_version' => 'fap.enneagram.result_page.agent_rollback_report.v0.1',
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'asset_key' => (string) ($payload['asset_key'] ?? ''),
            'rollback_shape' => 'remove_generated_payload_and_reports_before_candidate_export',
            'requires_database_rollback' => false,
            'requires_runtime_rollback' => false,
            'requires_frontend_rollback' => false,
            'requires_storage_cleanup' => false,
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    private function scanFields(array $payload): array
    {
        $hits = [];
        foreach ($payload as $key => $value) {
            $keyString = strtolower((string) $key);
            if (array_key_exists($keyString, self::FORBIDDEN_FIELD_FAMILY_BY_FIELD)) {
                $hits[] = [
                    'hit_type' => 'field_family',
                    'family' => self::FORBIDDEN_FIELD_FAMILY_BY_FIELD[$keyString],
                    'location' => 'public_payload',
                ];
            }

            if (is_array($value)) {
                $hits = array_merge($hits, $this->scanFields($value));
            }
        }

        return $hits;
    }

    /**
     * @return list<array<string,string>>
     */
    private function scanTerms(array $payload): array
    {
        $encoded = mb_strtolower(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $hits = [];

        foreach (self::FORBIDDEN_TERM_FAMILY_BY_TERM as $term => $family) {
            if (str_contains($encoded, mb_strtolower($term))) {
                $hits[] = [
                    'hit_type' => 'claim_family',
                    'family' => $family,
                    'location' => 'public_payload_text',
                ];
            }
        }

        return $hits;
    }

    /**
     * @return list<array<string,string>>
     */
    private function scanFc144Boundary(array $targetModule, array $payload): array
    {
        $moduleKey = mb_strtolower((string) ($targetModule['module_key'] ?? ''));
        $encoded = mb_strtolower(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        if (! str_contains($moduleKey, 'fc144') && ! str_contains($encoded, 'fc144')) {
            return [];
        }

        $hits = [];
        foreach ([
            'more accurate',
            'replace',
            'replaces',
            'replacement result',
            'final type',
            '更准确',
            '替代',
            '最终类型',
        ] as $term) {
            if (str_contains($encoded, mb_strtolower($term))) {
                $hits[] = [
                    'hit_type' => 'fc144_boundary',
                    'family' => 'fc144_more_accurate_or_replacement_result',
                    'location' => 'public_payload_text',
                ];
            }
        }

        return $hits;
    }

    /**
     * @param  array<string,mixed>  $previousPayload
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function changedFields(array $previousPayload, array $payload): array
    {
        $fields = [];
        foreach (array_unique(array_merge(array_keys($previousPayload), array_keys($payload))) as $key) {
            if (($previousPayload[$key] ?? null) !== ($payload[$key] ?? null)) {
                $fields[] = (string) $key;
            }
        }

        return $fields;
    }

    private function assetKey(string $resultType, string $moduleKey, string $scope, string $sourceId): string
    {
        $raw = $resultType.'_'.$moduleKey.'_'.$scope.'_'.$sourceId;
        $normalized = preg_replace('/[^a-z0-9_]+/', '_', strtolower($raw)) ?: 'enneagram_agent_payload';

        return trim($normalized, '_').'_'.substr(hash('sha256', $raw), 0, 10);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        $normalized = $this->sortRecursive($payload);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        $sorted = [];
        foreach ($value as $key => $child) {
            $sorted[$key] = $this->sortRecursive($child);
        }

        if (! $isList) {
            ksort($sorted);
        }

        return $sorted;
    }

    /**
     * @return array<string,bool|string>
     */
    private function negativeGuarantees(): array
    {
        return [
            'runtime_use' => 'not_runtime',
            'production_use_allowed' => false,
            'candidate_payload_creation_happened' => false,
            'candidate_export_happened' => false,
            'inactive_import_happened' => false,
            'activation_happened' => false,
            'runtime_change_performed' => false,
            'cms_write_performed' => false,
            'frontend_fallback_allowed' => false,
        ];
    }
}
