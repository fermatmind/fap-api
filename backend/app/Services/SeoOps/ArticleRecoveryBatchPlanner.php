<?php

declare(strict_types=1);

namespace App\Services\SeoOps;

use JsonException;

/** @review-surface article */
final class ArticleRecoveryBatchPlanner
{
    public const INPUT_SCHEMA = 'fermatmind-seo-10k-article-recovery-evidence.v1';

    public const QUERY_SCHEMA = 'fermatmind-seo-10k-article-recovery-query-summary.v1';

    public const PAGE_COHORT_SCHEMA = 'fermatmind-seo-10k-article-recovery-page-cohort-opaque-ids.v1';

    public const OUTPUT_SCHEMA = 'fermatmind-seo-10k-article-recovery-dry-run.v1';

    public const TASK = 'SEO-10K-ARTICLE-RECOVERY-BATCH-01';

    public const EXPECTED_EVIDENCE_SHA256 = '69eb07235831602faea7241b965c44561aeae68fe6e6e141d3a8e87d7d0fff03';

    private const EXPECTED_TARGET_COUNT = 5;

    private const PERFORMANCE_DETERIORATION_RULE = 'click_delta_lt_0_or_click_delta_eq_0_and_impression_delta_lt_0';

    /**
     * @var list<string>
     */
    private const REQUIRED_MANUAL_REVIEW_CHECKS = [
        'exact_target_set_sha_matches',
        'current_content_sha_matches',
        'current_seo_sha_matches',
        'query_owner_conflict_count_is_zero',
        'source_refs_support_visible_claims',
        'claim_boundaries_are_visible',
        'suppressed_query_target_is_explicitly_reviewed',
        'candidate_title_and_description_are_approved',
    ];

    /**
     * @var list<string>
     */
    private const REQUIRED_OBSERVATION_METRICS = [
        'page_clicks',
        'page_impressions',
        'page_ctr',
        'page_average_position',
        'query_owner_conflicts',
        'canonical_robots_and_indexability',
    ];

    /**
     * Normalized secret-bearing keys forbidden in both structured evidence
     * and source URL query parameters.
     *
     * @var list<string>
     */
    private const CREDENTIAL_KEYS = [
        'access_token',
        'api_key',
        'api_secret',
        'authorization',
        'auth_token',
        'bearer_token',
        'client_secret',
        'cookie',
        'credential',
        'credentials',
        'id_token',
        'password',
        'passwd',
        'private_key',
        'refresh_token',
        'secret',
        'session_token',
        'signature',
        'signing_key',
        'token',
    ];

    public function __construct(
        private readonly string $lockedEvidenceSha256 = self::EXPECTED_EVIDENCE_SHA256,
    ) {}

    /**
     * Keys that would persist raw search or article content instead of bounded evidence.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'query',
        'raw_query',
        'query_text',
        'query_display',
        'query_display_masked',
        'content_md',
        'content_html',
        'credential',
        'credentials',
        'api_key',
        'api_secret',
        'authorization',
        'auth_token',
        'bearer_token',
        'client_secret',
        'id_token',
        'password',
        'passwd',
        'refresh_token',
        'secret',
        'session_token',
        'signature',
        'signing_key',
        'token',
        'private_key',
        'access_token',
        'cookie',
    ];

    /**
     * @return array<string, mixed>
     */
    public function plan(string $evidencePath, string $expectedEvidenceSha256): array
    {
        $issues = [];
        if (! hash_equals($this->lockedEvidenceSha256, $expectedEvidenceSha256)) {
            return $this->blocked(['evidence_sha_not_locked_to_batch']);
        }

        $loaded = $this->loadJson($evidencePath, $expectedEvidenceSha256, self::INPUT_SCHEMA, $issues);
        if ($loaded === null) {
            return $this->blocked($issues);
        }

        $evidence = $loaded['payload'];
        $queryArtifact = $this->loadQueryArtifact($evidencePath, $evidence, $issues);
        if ($queryArtifact === null) {
            return $this->blocked($issues, $loaded);
        }
        $pageCohortArtifact = $this->loadPageCohortArtifact($evidencePath, $evidence, $issues);
        if ($pageCohortArtifact === null) {
            return $this->blocked($issues, $loaded);
        }

        $targets = array_values((array) ($evidence['targets'] ?? []));
        $this->validateTopLevel($evidence, $targets, $issues);
        $querySummary = $this->validateQueryEvidence($targets, $queryArtifact['payload'], $issues);
        $this->validatePageCohortEvidence($evidence, $targets, $pageCohortArtifact['payload'], $issues);
        $gscReviewProvenance = [
            'source_evidence_sha256' => $loaded['sha256'],
            'page_cohort_artifact_sha256' => $pageCohortArtifact['sha256'],
            'observed_at' => (string) ($evidence['observed_at'] ?? ''),
            'data_origin' => (string) data_get($evidence, 'gsc.data_origin', ''),
            'source_engine' => (string) data_get($evidence, 'gsc.source_engine', ''),
            'property' => (string) data_get($evidence, 'gsc.property', ''),
            'search_type' => (string) data_get($evidence, 'gsc.search_type', ''),
            'current_window' => (array) data_get($evidence, 'gsc.current_window', []),
            'previous_window' => (array) data_get($evidence, 'gsc.previous_window', []),
            'page_export' => (array) data_get($evidence, 'gsc.page_export', []),
        ];
        $targetPlans = $this->validateTargets(
            $targets,
            $queryArtifact['payload'],
            $queryArtifact['sha256'],
            $gscReviewProvenance,
            $issues,
        );
        $this->validateTargetSetSha($evidence, $targets, $issues);
        $this->validateManualReviewAndObservation($evidence, $issues);
        $formalReadmodelGateIssue = $this->formalReadmodelGateIssue();

        $issues = array_values(array_unique($issues));
        sort($issues);

        if ($issues !== []) {
            return $this->blocked($issues, [
                ...$loaded,
                'query_artifact_sha256' => $queryArtifact['sha256'],
                'page_cohort_artifact_sha256' => $pageCohortArtifact['sha256'],
            ]);
        }

        $suppressedCount = count(array_filter(
            $targetPlans,
            static fn (array $target): bool => ($target['query_evidence_state'] ?? null) === 'privacy_threshold_suppressed'
        ));

        $package = [
            'schema_version' => self::OUTPUT_SCHEMA,
            'task' => self::TASK,
            'batch_id' => (string) ($evidence['batch_id'] ?? ''),
            'status' => $formalReadmodelGateIssue === null
                ? ($suppressedCount > 0
                    ? 'manual_review_pending_with_query_privacy_hold'
                    : 'manual_review_pending')
                : 'blocked_formal_gsc_readmodel_gate',
            'ok' => $formalReadmodelGateIssue === null,
            'dry_run' => true,
            'would_write' => false,
            'candidate_package_built' => true,
            'approval_eligible' => $formalReadmodelGateIssue === null,
            'issues' => $formalReadmodelGateIssue === null ? [] : [$formalReadmodelGateIssue],
            'source_evidence' => [
                'sha256' => $loaded['sha256'],
                'query_summary_artifact_sha256' => $queryArtifact['sha256'],
                'page_cohort_artifact_sha256' => $pageCohortArtifact['sha256'],
                'observed_at' => (string) ($evidence['observed_at'] ?? ''),
                'data_origin' => (string) data_get($evidence, 'gsc.data_origin', ''),
                'property' => (string) data_get($evidence, 'gsc.property', ''),
                'search_type' => (string) data_get($evidence, 'gsc.search_type', ''),
                'evidence_role' => 'contextual_candidate_ranking_only',
                'current_window' => (array) data_get($evidence, 'gsc.current_window', []),
                'previous_window' => (array) data_get($evidence, 'gsc.previous_window', []),
            ],
            'selection' => [
                'rule' => (string) data_get($evidence, 'selection.rule', ''),
                'target_count' => count($targetPlans),
                'exact_existing_urls' => true,
                'new_urls' => 0,
            ],
            'query_owner' => [
                'status' => 'pass',
                'conflict_count' => 0,
                'retained_query_count' => $querySummary['retained_query_count'],
                'suppressed_target_count' => $suppressedCount,
                'raw_query_persisted' => false,
            ],
            'formal_readmodel_gate' => $this->blockedFormalReadmodelGate(),
            'target_set_sha256' => (string) ($evidence['target_set_sha256'] ?? ''),
            'targets' => $targetPlans,
            'manual_review_gate' => [
                ...(array) ($evidence['manual_review_gate'] ?? []),
                'status' => $formalReadmodelGateIssue === null ? 'pending' : 'blocked_formal_gsc_readmodel_gate',
            ],
            'observation_contract' => (array) ($evidence['observation_contract'] ?? []),
            'negative_guarantees' => (array) ($evidence['negative_guarantees'] ?? []),
        ];

        $package['package_sha256'] = hash('sha256', $this->canonicalJson($package));

        return $package;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    public function prettyJson(array $package): string
    {
        return (string) json_encode(
            $this->sortRecursively($package),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        )."\n";
    }

    /**
     * @param  list<string>  $issues
     * @return array{payload: array<string, mixed>, sha256: string, path: string}|null
     */
    private function loadJson(
        string $path,
        string $expectedSha256,
        string $expectedSchema,
        array &$issues,
    ): ?array {
        $resolved = realpath($path);
        if (! is_string($resolved) || ! is_file($resolved) || ! is_readable($resolved)) {
            $issues[] = 'evidence_unreadable';

            return null;
        }

        $raw = (string) file_get_contents($resolved);
        $actualSha256 = hash('sha256', $raw);
        if (! $this->validHash($expectedSha256) || ! hash_equals($expectedSha256, $actualSha256)) {
            $issues[] = 'evidence_sha_mismatch';

            return null;
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $issues[] = 'evidence_json_invalid';

            return null;
        }

        if (! is_array($payload)) {
            $issues[] = 'evidence_json_not_object';

            return null;
        }
        if (($payload['schema'] ?? null) !== $expectedSchema) {
            $issues[] = 'evidence_schema_invalid';
        }
        if ($this->forbiddenKeys($payload) !== []) {
            $issues[] = 'forbidden_raw_or_private_field_present';
        }
        if (! $this->artifactSchemaKeysAreExact($payload, $expectedSchema)) {
            $issues[] = 'artifact_field_allowlist_invalid';
        }

        return [
            'payload' => $payload,
            'sha256' => $actualSha256,
            'path' => $resolved,
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $issues
     * @return array{payload: array<string, mixed>, sha256: string, path: string}|null
     */
    private function loadQueryArtifact(string $evidencePath, array $evidence, array &$issues): ?array
    {
        $relativePath = trim((string) data_get($evidence, 'gsc.query_summary_artifact.path', ''));
        $expectedSha256 = trim((string) data_get($evidence, 'gsc.query_summary_artifact.sha256', ''));
        if ($relativePath === '' || basename($relativePath) !== $relativePath) {
            $issues[] = 'query_summary_artifact_path_invalid';

            return null;
        }

        $queryPath = dirname((string) realpath($evidencePath)).DIRECTORY_SEPARATOR.$relativePath;

        return $this->loadJson($queryPath, $expectedSha256, self::QUERY_SCHEMA, $issues);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $issues
     * @return array{payload: array<string, mixed>, sha256: string, path: string}|null
     */
    private function loadPageCohortArtifact(string $evidencePath, array $evidence, array &$issues): ?array
    {
        $relativePath = trim((string) data_get($evidence, 'gsc.page_cohort_artifact.path', ''));
        $expectedSha256 = trim((string) data_get($evidence, 'gsc.page_cohort_artifact.sha256', ''));
        if ($relativePath === '' || basename($relativePath) !== $relativePath) {
            $issues[] = 'page_cohort_artifact_path_invalid';

            return null;
        }

        $cohortPath = dirname((string) realpath($evidencePath)).DIRECTORY_SEPARATOR.$relativePath;

        return $this->loadJson($cohortPath, $expectedSha256, self::PAGE_COHORT_SCHEMA, $issues);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<mixed>  $targets
     * @param  list<string>  $issues
     */
    private function validateTopLevel(array $evidence, array $targets, array &$issues): void
    {
        if (($evidence['task'] ?? null) !== self::TASK) {
            $issues[] = 'task_invalid';
        }
        if (count($targets) !== self::EXPECTED_TARGET_COUNT) {
            $issues[] = 'target_count_must_equal_five';
        }
        if ((int) data_get($evidence, 'scope.exact_existing_url_count', 0) !== self::EXPECTED_TARGET_COUNT) {
            $issues[] = 'scope_target_count_invalid';
        }
        if ((string) data_get($evidence, 'gsc.data_origin', '') !== 'live_gsc_browser_export') {
            $issues[] = 'gsc_data_origin_not_live';
        }
        if ((string) data_get($evidence, 'gsc.source_engine', '') !== 'google') {
            $issues[] = 'gsc_source_engine_invalid';
        }
        if (data_get($evidence, 'gsc.property') !== 'sc-domain:fermatmind.com'
            || data_get($evidence, 'gsc.search_type') !== 'web') {
            $issues[] = 'gsc_property_or_search_type_invalid';
        }
        if ($this->canonicalJson((array) data_get($evidence, 'gsc.formal_readmodel_gate', []))
            !== $this->canonicalJson($this->blockedFormalReadmodelGate())) {
            $issues[] = 'formal_readmodel_gate_invalid';
        }
        if (! $this->validHash((string) data_get($evidence, 'gsc.page_export.zip_sha256', ''))
            || ! $this->validHash((string) data_get($evidence, 'gsc.page_export.csv_sha256', ''))) {
            $issues[] = 'gsc_page_export_hash_invalid';
        }
        if (! is_int(data_get($evidence, 'gsc.page_export.total_row_count'))
            || data_get($evidence, 'gsc.page_export.total_row_count') < self::EXPECTED_TARGET_COUNT) {
            $issues[] = 'gsc_page_export_count_invalid';
        }
        if ((string) data_get($evidence, 'selection.rule', '') !== 'click_delta_asc_then_impression_delta_asc_then_page_evidence_id_asc') {
            $issues[] = 'selection_rule_invalid';
        }
        if ((string) data_get($evidence, 'selection.eligibility_rule', '') !== self::PERFORMANCE_DETERIORATION_RULE) {
            $issues[] = 'selection_eligibility_rule_invalid';
        }
        if (! $this->validComparisonWindows(
            (array) data_get($evidence, 'gsc.previous_window', []),
            (array) data_get($evidence, 'gsc.current_window', []),
            (string) ($evidence['observed_at'] ?? ''),
        )) {
            $issues[] = 'gsc_comparison_windows_invalid';
        }
        if ((int) data_get($evidence, 'selection.page_export_article_row_count', 0) < self::EXPECTED_TARGET_COUNT) {
            $issues[] = 'page_export_article_cohort_too_small';
        }
        if (data_get($evidence, 'gsc.query_summary_artifact.raw_query_persisted') !== false
            || data_get($evidence, 'gsc.query_summary_artifact.unkeyed_query_digest_persisted') !== false) {
            $issues[] = 'raw_query_persistence_attested';
        }
        if (data_get($evidence, 'gsc.page_cohort_artifact.raw_url_persisted') !== false) {
            $issues[] = 'raw_url_persistence_attested';
        }
        if (data_get($evidence, 'gsc.page_cohort_artifact.unkeyed_url_digest_persisted') !== false
            || data_get($evidence, 'gsc.page_cohort_artifact.identifier_model')
                !== 'opaque_random_ids_mapping_not_persisted') {
            $issues[] = 'page_cohort_identifier_privacy_invalid';
        }

        foreach ([
            'new_url_allowed',
            'database_write_allowed',
            'cms_write_allowed',
            'publish_allowed',
            'indexability_write_allowed',
            'search_submit_allowed',
            'scheduler_allowed',
            'queue_allowed',
            'deploy_allowed',
        ] as $field) {
            if (data_get($evidence, 'scope.'.$field) !== false) {
                $issues[] = 'scope_flag_must_be_false:'.$field;
            }
        }

        foreach ((array) ($evidence['negative_guarantees'] ?? []) as $field => $value) {
            if ($value !== false) {
                $issues[] = 'negative_guarantee_must_be_false:'.$field;
            }
        }
    }

    /**
     * @param  list<mixed>  $targets
     * @param  array<string, mixed>  $queryArtifact
     * @param  list<string>  $issues
     * @return array{retained_query_count: int}
     */
    private function validateQueryEvidence(array $targets, array $queryArtifact, array &$issues): array
    {
        if (($queryArtifact['task'] ?? null) !== self::TASK) {
            $issues[] = 'query_artifact_task_invalid';
        }
        if (($queryArtifact['data_origin'] ?? null) !== 'live_gsc_browser_export') {
            $issues[] = 'query_artifact_origin_not_live';
        }
        if (($queryArtifact['source_engine'] ?? null) !== 'google') {
            $issues[] = 'query_artifact_source_engine_invalid';
        }
        if (($queryArtifact['raw_query_persisted'] ?? null) !== false) {
            $issues[] = 'raw_query_persistence_attested';
        }
        if (($queryArtifact['unkeyed_query_digest_persisted'] ?? null) !== false) {
            $issues[] = 'unkeyed_query_digest_persistence_attested';
        }
        if (($queryArtifact['privacy_model'] ?? null) !== 'counts_only_no_query_text_or_unkeyed_digest') {
            $issues[] = 'query_summary_privacy_model_invalid';
        }
        if (($queryArtifact['cross_target_owner_conflict_check']['performed_before_sanitization'] ?? null) !== true
            || ($queryArtifact['cross_target_owner_conflict_check']['conflict_count'] ?? null) !== 0) {
            $issues[] = 'query_owner_conflict_present';
        }

        $targetUrls = array_map(
            static fn (mixed $target): string => is_array($target) ? (string) ($target['canonical_url'] ?? '') : '',
            $targets
        );
        $queryTargets = (array) ($queryArtifact['target_summaries'] ?? []);
        $queryTargetUrls = array_keys($queryTargets);
        sort($targetUrls);
        sort($queryTargetUrls);
        if ($targetUrls !== $queryTargetUrls) {
            $issues[] = 'query_target_set_mismatch';
        }

        $retainedCount = 0;
        foreach ($targets as $target) {
            if (! is_array($target)) {
                $issues[] = 'target_not_object';

                continue;
            }

            $url = (string) ($target['canonical_url'] ?? '');
            $summary = (array) ($queryTargets[$url] ?? []);
            $retainedQueryCount = $summary['retained_query_count'] ?? null;
            if (! is_int($retainedQueryCount) || $retainedQueryCount < 0) {
                $issues[] = 'retained_query_count_invalid:'.$url;
                $retainedQueryCount = 0;
            }
            $retainedCount += $retainedQueryCount;
            $rawRowCount = data_get($target, 'query_export.raw_row_count');
            $targetRetainedCount = data_get($target, 'query_export.retained_query_count');
            $targetSiteOperatorCount = data_get($target, 'query_export.excluded_site_operator_count');
            $targetBrandOrMixedCount = data_get($target, 'query_export.excluded_brand_or_mixed_count');
            if (! is_int($rawRowCount)
                || ! is_int($targetRetainedCount)
                || ! is_int($targetSiteOperatorCount)
                || ! is_int($targetBrandOrMixedCount)
                || $rawRowCount < 0
                || $targetRetainedCount < 0
                || $targetSiteOperatorCount < 0
                || $targetBrandOrMixedCount < 0) {
                $issues[] = 'query_export_count_invalid:'.$url;
            }
            if ($retainedQueryCount !== $targetRetainedCount) {
                $issues[] = 'retained_query_count_mismatch:'.$url;
            }

            $excluded = (array) ($summary['excluded'] ?? []);
            $summarySiteOperatorCount = $excluded['site_operator'] ?? null;
            $summaryBrandOrMixedCount = $excluded['brand_or_mixed'] ?? null;
            if (! is_int($summarySiteOperatorCount)
                || ! is_int($summaryBrandOrMixedCount)
                || $summarySiteOperatorCount < 0
                || $summaryBrandOrMixedCount < 0) {
                $issues[] = 'query_summary_exclusion_count_invalid:'.$url;
            }
            if ($summarySiteOperatorCount !== $targetSiteOperatorCount
                || $summaryBrandOrMixedCount !== $targetBrandOrMixedCount) {
                $issues[] = 'query_exclusion_count_mismatch:'.$url;
            }
            if (is_int($rawRowCount)
                && is_int($targetRetainedCount)
                && is_int($targetSiteOperatorCount)
                && is_int($targetBrandOrMixedCount)
                && $rawRowCount !== $targetRetainedCount + $targetSiteOperatorCount + $targetBrandOrMixedCount) {
                $issues[] = 'query_export_count_conservation_failed:'.$url;
            }

            $state = (string) data_get($target, 'query_export.evidence_state', '');
            if (($summary['evidence_state'] ?? null) !== $state) {
                $issues[] = 'query_evidence_state_mismatch:'.$url;
            }
            if ($retainedQueryCount === 0 && $state !== 'privacy_threshold_suppressed') {
                $issues[] = 'missing_query_count_without_privacy_hold:'.$url;
            }
            if ($retainedQueryCount > 0 && $state !== 'available') {
                $issues[] = 'available_query_count_state_invalid:'.$url;
            }
        }

        return ['retained_query_count' => $retainedCount];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<mixed>  $targets
     * @param  array<string, mixed>  $cohort
     * @param  list<string>  $issues
     */
    private function validatePageCohortEvidence(
        array $evidence,
        array $targets,
        array $cohort,
        array &$issues,
    ): void {
        $expectedArticleCount = (int) data_get($evidence, 'selection.page_export_article_row_count', 0);
        $rows = array_values((array) ($cohort['rows'] ?? []));

        if (($cohort['task'] ?? null) !== self::TASK
            || ($cohort['data_origin'] ?? null) !== 'live_gsc_browser_export'
            || ($cohort['evidence_role'] ?? null) !== 'contextual_candidate_ranking_only'
            || ($cohort['formal_readmodel_gate_passed'] ?? null) !== false
            || ($cohort['source_engine'] ?? null) !== 'google'
            || ($cohort['search_type'] ?? null) !== 'web') {
            $issues[] = 'page_cohort_provenance_invalid';
        }
        if (($cohort['raw_url_persisted'] ?? null) !== false
            || ($cohort['unkeyed_url_digest_persisted'] ?? null) !== false
            || ($cohort['identifier_model'] ?? null) !== 'opaque_random_ids_mapping_not_persisted') {
            $issues[] = 'raw_url_persistence_attested';
        }
        if (($cohort['selection_rule'] ?? null)
            !== 'click_delta_asc_then_impression_delta_asc_then_page_evidence_id_asc') {
            $issues[] = 'page_cohort_selection_rule_invalid';
        }
        if (($cohort['eligibility_rule'] ?? null) !== self::PERFORMANCE_DETERIORATION_RULE) {
            $issues[] = 'page_cohort_eligibility_rule_invalid';
        }
        if ((string) ($cohort['source_csv_sha256'] ?? '')
            !== (string) data_get($evidence, 'gsc.page_export.csv_sha256', '')
            || (int) ($cohort['source_total_row_count'] ?? 0)
            !== (int) data_get($evidence, 'gsc.page_export.total_row_count', 0)) {
            $issues[] = 'page_cohort_source_export_mismatch';
        }
        if ((array) ($cohort['current_window'] ?? []) !== (array) data_get($evidence, 'gsc.current_window', [])
            || (array) ($cohort['previous_window'] ?? []) !== (array) data_get($evidence, 'gsc.previous_window', [])) {
            $issues[] = 'page_cohort_window_mismatch';
        }
        if ($expectedArticleCount < self::EXPECTED_TARGET_COUNT
            || (int) ($cohort['article_row_count'] ?? 0) !== $expectedArticleCount
            || count($rows) !== $expectedArticleCount
            || (int) data_get($evidence, 'gsc.page_cohort_artifact.article_row_count', 0) !== $expectedArticleCount) {
            $issues[] = 'page_cohort_count_mismatch';
        }

        $seenPageEvidenceIds = [];
        $previousLossKey = null;
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $issues[] = 'page_cohort_row_invalid';

                continue;
            }

            $pageEvidenceId = (string) ($row['page_evidence_id'] ?? '');
            $rank = $row['rank'] ?? null;
            $currentClicks = $row['current_clicks'] ?? null;
            $previousClicks = $row['previous_clicks'] ?? null;
            $currentImpressions = $row['current_impressions'] ?? null;
            $previousImpressions = $row['previous_impressions'] ?? null;
            $clickDelta = $row['click_delta'] ?? null;
            $impressionDelta = $row['impression_delta'] ?? null;
            $countsAreIntegers = is_int($currentClicks)
                && is_int($previousClicks)
                && is_int($currentImpressions)
                && is_int($previousImpressions)
                && is_int($clickDelta)
                && is_int($impressionDelta);
            $lossKey = [$clickDelta, $impressionDelta, $pageEvidenceId];

            if (! is_int($rank)
                || $rank !== $index + 1
                || ! $this->validPageEvidenceId($pageEvidenceId)
                || isset($seenPageEvidenceIds[$pageEvidenceId])
                || ! $countsAreIntegers
                || $currentClicks < 0
                || $previousClicks < 0
                || $currentImpressions < 0
                || $previousImpressions < 0
                || $clickDelta !== $currentClicks - $previousClicks
                || $impressionDelta !== $currentImpressions - $previousImpressions
                || $currentClicks > $currentImpressions
                || $previousClicks > $previousImpressions
                || ! $this->validPosition($row['current_position'] ?? null)
                || ! $this->validPosition($row['previous_position'] ?? null)) {
                $issues[] = 'page_cohort_row_invalid';
            }
            if ($countsAreIntegers && $previousLossKey !== null && $lossKey < $previousLossKey) {
                $issues[] = 'page_cohort_ranking_invalid';
            }

            $seenPageEvidenceIds[$pageEvidenceId] = true;
            $previousLossKey = $countsAreIntegers ? $lossKey : null;
        }

        $expectedTopFive = array_map(
            static fn (mixed $target): string => is_array($target) ? (string) ($target['page_evidence_id'] ?? '') : '',
            $targets
        );
        $actualTopFive = array_column(array_slice($rows, 0, self::EXPECTED_TARGET_COUNT), 'page_evidence_id');
        if ((array) ($cohort['top_five_page_evidence_ids'] ?? []) !== $expectedTopFive
            || $actualTopFive !== $expectedTopFive) {
            $issues[] = 'page_cohort_top_five_mismatch';
        }
        foreach (array_slice($rows, 0, self::EXPECTED_TARGET_COUNT) as $row) {
            $clickDelta = is_array($row) ? ($row['click_delta'] ?? null) : null;
            $impressionDelta = is_array($row) ? ($row['impression_delta'] ?? null) : null;
            if (! is_int($clickDelta)
                || ! is_int($impressionDelta)
                || ! ($clickDelta < 0 || ($clickDelta === 0 && $impressionDelta < 0))) {
                $issues[] = 'insufficient_performance_deterioration_targets';

                break;
            }
        }

        foreach ($targets as $index => $target) {
            if (! is_array($target) || ! isset($rows[$index]) || ! is_array($rows[$index])) {
                continue;
            }

            foreach ([
                'current_clicks',
                'previous_clicks',
                'click_delta',
                'current_impressions',
                'previous_impressions',
                'impression_delta',
            ] as $field) {
                if (($rows[$index][$field] ?? null) !== data_get($target, 'gsc_page.'.$field)) {
                    $issues[] = 'page_cohort_target_metric_mismatch:'.(string) ($target['canonical_url'] ?? '');
                }
            }
            foreach (['current_position', 'previous_position'] as $field) {
                $cohortPosition = $rows[$index][$field] ?? null;
                $targetPosition = data_get($target, 'gsc_page.'.$field);
                if (! $this->validPosition($cohortPosition)
                    || ! $this->validPosition($targetPosition)
                    || (float) $cohortPosition !== (float) $targetPosition) {
                    $issues[] = 'page_cohort_target_metric_mismatch:'.(string) ($target['canonical_url'] ?? '');
                }
            }
        }

        if (data_get($cohort, 'cutoff_attestation.rank_5') !== ($rows[4] ?? null)
            || data_get($cohort, 'cutoff_attestation.rank_6') !== ($rows[5] ?? null)) {
            $issues[] = 'page_cohort_cutoff_attestation_invalid';
        }
    }

    /**
     * Browser exports may identify contextual candidates, but this local
     * planner cannot verify production read-model rows. A future separately
     * scoped backend verifier must compute a receipt from authoritative rows;
     * a self-attested JSON artifact can never make this package eligible.
     */
    private function formalReadmodelGateIssue(): string
    {
        return 'formal_gsc_readmodel_gate_not_passed';
    }

    /**
     * @return array<string, mixed>
     */
    private function blockedFormalReadmodelGate(): array
    {
        return [
            'status' => 'blocked',
            'required_data_origin' => 'live_gsc_api',
            'required_row_source' => 'live_gsc_api',
            'required_source_engine' => 'google',
            'required_data_state' => 'final',
            'required_article_cohort_coverage_count' => 54,
            'actual_eligible_article_cohort_coverage_count' => 0,
            'opportunity_queue_eligible' => false,
            'reason' => 'The completed 10-row production canary covers only the zh MBTI test page and does not cover this 54-article cohort.',
        ];
    }

    /**
     * @param  list<mixed>  $targets
     * @param  array<string, mixed>  $queryArtifact
     * @param  array<string, mixed>  $gscReviewProvenance
     * @param  list<string>  $issues
     * @return list<array<string, mixed>>
     */
    private function validateTargets(
        array $targets,
        array $queryArtifact,
        string $queryArtifactSha256,
        array $gscReviewProvenance,
        array &$issues,
    ): array {
        $plans = [];
        $seenUrls = [];
        $seenSlugs = [];
        $ranked = [];

        foreach ($targets as $index => $target) {
            if (! is_array($target)) {
                $issues[] = 'target_not_object';

                continue;
            }

            $rank = (int) ($target['rank'] ?? 0);
            $url = trim((string) ($target['canonical_url'] ?? ''));
            $slug = trim((string) ($target['slug'] ?? ''));
            $locale = trim((string) ($target['locale'] ?? ''));
            $expectedSegment = $locale === 'zh-CN' ? 'zh' : ($locale === 'en' ? 'en' : '');
            $expectedUrl = $expectedSegment === '' || $slug === ''
                ? ''
                : 'https://fermatmind.com/'.$expectedSegment.'/articles/'.$slug;

            if ($rank !== $index + 1) {
                $issues[] = 'rank_sequence_invalid';
            }
            if ($url === '' || $url !== $expectedUrl || ! $this->safeArticleUrl($url)) {
                $issues[] = 'canonical_url_invalid:'.$rank;
            }
            if (isset($seenUrls[$url]) || isset($seenSlugs[$locale.'|'.$slug])) {
                $issues[] = 'duplicate_target_identity';
            }
            $seenUrls[$url] = true;
            $seenSlugs[$locale.'|'.$slug] = true;

            if (! $this->validPageEvidenceId((string) ($target['page_evidence_id'] ?? ''))) {
                $issues[] = 'page_evidence_id_invalid:'.$url;
            }

            $this->validateCurrentAuthority($target, $url, $issues);
            $this->validateGscPage($target, $url, $issues);
            $this->validateRecoveryAndClaims($target, $url, $issues);

            $ranked[] = [
                'rank' => $rank,
                'canonical_url' => $url,
                'page_evidence_id' => (string) ($target['page_evidence_id'] ?? ''),
                'click_delta' => (int) data_get($target, 'gsc_page.click_delta', 0),
                'impression_delta' => (int) data_get($target, 'gsc_page.impression_delta', 0),
            ];

            $targetQuerySummary = (array) (($queryArtifact['target_summaries'] ?? [])[$url] ?? []);
            $targetQuerySummarySha256 = hash('sha256', $this->canonicalJson($targetQuerySummary));
            $queryReviewEvidence = [
                'evidence_state' => (string) data_get($target, 'query_export.evidence_state', ''),
                'retained_query_count' => (int) data_get(
                    $target,
                    'query_export.retained_query_count',
                    0,
                ),
                'private_query_export_zip_sha256' => (string) data_get(
                    $target,
                    'query_export.zip_sha256',
                    '',
                ),
                'private_query_export_csv_sha256' => (string) data_get(
                    $target,
                    'query_export.csv_sha256',
                    '',
                ),
                'query_summary_artifact_sha256' => $queryArtifactSha256,
                'target_query_summary_sha256' => $targetQuerySummarySha256,
            ];
            $reviewMaterial = [
                'rank' => $rank,
                'article_id' => (int) ($target['article_id'] ?? 0),
                'locale' => $locale,
                'slug' => $slug,
                'canonical_url' => $url,
                'page_evidence_id' => (string) ($target['page_evidence_id'] ?? ''),
                'current_authority' => (array) ($target['current_authority'] ?? []),
                'gsc_page' => (array) ($target['gsc_page'] ?? []),
                'gsc_provenance' => $gscReviewProvenance,
                'query_evidence' => $queryReviewEvidence,
                'proposed_recovery' => (array) ($target['proposed_recovery'] ?? []),
                'source_refs' => (array) ($target['source_refs'] ?? []),
                'claim_boundary' => (array) ($target['claim_boundary'] ?? []),
            ];

            $plans[] = [
                'rank' => $rank,
                'article_id' => (int) ($target['article_id'] ?? 0),
                'slug' => $slug,
                'locale' => $locale,
                'canonical_url' => $url,
                'page_evidence_id' => (string) ($target['page_evidence_id'] ?? ''),
                'url_lock' => true,
                'new_url' => false,
                'current_content_sha256' => (string) data_get($target, 'current_authority.content_sha256', ''),
                'current_seo_sha256' => (string) data_get($target, 'current_authority.seo_sha256', ''),
                'gsc_page' => (array) ($target['gsc_page'] ?? []),
                'gsc_provenance' => $gscReviewProvenance,
                'query_evidence_state' => (string) data_get($target, 'query_export.evidence_state', ''),
                'retained_query_count' => (int) data_get($target, 'query_export.retained_query_count', 0),
                'private_query_export_zip_sha256' => (string) data_get($target, 'query_export.zip_sha256', ''),
                'private_query_export_csv_sha256' => (string) data_get($target, 'query_export.csv_sha256', ''),
                'query_summary_artifact_sha256' => $queryArtifactSha256,
                'target_query_summary_sha256' => $targetQuerySummarySha256,
                'proposed_recovery' => (array) ($target['proposed_recovery'] ?? []),
                'source_refs' => (array) ($target['source_refs'] ?? []),
                'claim_boundary' => (array) ($target['claim_boundary'] ?? []),
                'review_status' => 'pending',
                'review_target_sha256' => hash('sha256', $this->canonicalJson($reviewMaterial)),
            ];
        }

        $expectedRanked = $ranked;
        usort($expectedRanked, static function (array $left, array $right): int {
            return [$left['click_delta'], $left['impression_delta'], $left['page_evidence_id']]
                <=> [$right['click_delta'], $right['impression_delta'], $right['page_evidence_id']];
        });
        if (array_column($ranked, 'canonical_url') !== array_column($expectedRanked, 'canonical_url')) {
            $issues[] = 'target_ranking_not_deterministic';
        }

        return $plans;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  list<string>  $issues
     */
    private function validateCurrentAuthority(array $target, string $url, array &$issues): void
    {
        $authority = (array) ($target['current_authority'] ?? []);
        if ((int) ($target['article_id'] ?? 0) <= 0
            || (int) ($authority['public_api_http_status'] ?? 0) !== 200
            || (int) ($authority['published_revision_id'] ?? 0) <= 0
            || ($authority['status'] ?? null) !== 'published'
            || ($authority['is_public'] ?? null) !== true
            || ($authority['is_indexable'] ?? null) !== true
            || ($authority['sitemap_eligible'] ?? null) !== true
            || ($authority['llms_eligible'] ?? null) !== true
            || ($authority['review_state'] ?? null) !== 'approved') {
            $issues[] = 'existing_public_article_authority_invalid:'.$url;
        }
        if (! $this->validHash((string) ($authority['content_sha256'] ?? ''))) {
            $issues[] = 'content_sha_invalid:'.$url;
        }
        if (! $this->validHash((string) ($authority['seo_sha256'] ?? ''))) {
            $issues[] = 'seo_sha_invalid:'.$url;
        }
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  list<string>  $issues
     */
    private function validateGscPage(array $target, string $url, array &$issues): void
    {
        $page = (array) ($target['gsc_page'] ?? []);
        $currentClicks = $page['current_clicks'] ?? null;
        $previousClicks = $page['previous_clicks'] ?? null;
        $clickDelta = $page['click_delta'] ?? null;
        $currentImpressions = $page['current_impressions'] ?? null;
        $previousImpressions = $page['previous_impressions'] ?? null;
        $impressionDelta = $page['impression_delta'] ?? null;

        if (! is_int($currentClicks)
            || ! is_int($previousClicks)
            || ! is_int($clickDelta)
            || $currentClicks < 0
            || $previousClicks < 0
            || $clickDelta !== $currentClicks - $previousClicks) {
            $issues[] = 'gsc_click_delta_mismatch:'.$url;
        }
        if (! is_int($currentImpressions)
            || ! is_int($previousImpressions)
            || ! is_int($impressionDelta)
            || $currentImpressions < 0
            || $previousImpressions < 0
            || $impressionDelta !== $currentImpressions - $previousImpressions) {
            $issues[] = 'gsc_impression_delta_mismatch:'.$url;
        }
        if (is_int($currentClicks)
            && is_int($previousClicks)
            && is_int($currentImpressions)
            && is_int($previousImpressions)
            && ($currentClicks > $currentImpressions || $previousClicks > $previousImpressions)) {
            $issues[] = 'gsc_click_impression_relationship_invalid:'.$url;
        }
        foreach (['current_position', 'previous_position'] as $field) {
            if (! $this->validPosition($page[$field] ?? null)) {
                $issues[] = 'gsc_position_invalid:'.$url;
            }
        }
        foreach (['zip_sha256', 'csv_sha256'] as $field) {
            if (! $this->validHash((string) data_get($target, 'query_export.'.$field, ''))) {
                $issues[] = 'query_export_'.$field.'_invalid:'.$url;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  list<string>  $issues
     */
    private function validateRecoveryAndClaims(array $target, string $url, array &$issues): void
    {
        $recovery = (array) ($target['proposed_recovery'] ?? []);
        $titleCandidate = trim((string) ($recovery['title_candidate'] ?? ''));
        $metaDescriptionCandidate = trim((string) ($recovery['meta_description_candidate'] ?? ''));
        $candidateText = $titleCandidate.' '.$metaDescriptionCandidate;
        if ($titleCandidate === ''
            || $metaDescriptionCandidate === ''
            || count((array) ($recovery['visible_section_actions'] ?? [])) < 2) {
            $issues[] = 'recovery_plan_incomplete:'.$url;
        }
        foreach (['guaranteed', 'guarantees', 'absolutely accurate', '绝对准确', '保证录用', '保证收入'] as $forbidden) {
            if (mb_stripos($candidateText, $forbidden, 0, 'UTF-8') !== false) {
                $issues[] = 'recovery_candidate_overclaim:'.$url;
            }
        }

        $sourceRefs = array_values((array) ($target['source_refs'] ?? []));
        if (count($sourceRefs) < 2) {
            $issues[] = 'source_refs_incomplete:'.$url;
        }
        $sourceIds = [];
        foreach ($sourceRefs as $source) {
            if (! is_array($source)) {
                $issues[] = 'source_ref_invalid:'.$url;

                continue;
            }
            $id = trim((string) ($source['id'] ?? ''));
            $sourceUrl = trim((string) ($source['url'] ?? ''));
            if ($id === '' || isset($sourceIds[$id]) || ! $this->safeSourceUrl($sourceUrl)
                || trim((string) ($source['authority_type'] ?? '')) === '') {
                $issues[] = 'source_ref_invalid:'.$url;
            }
            $sourceIds[$id] = true;
        }

        $claimBoundary = (array) ($target['claim_boundary'] ?? []);
        if ((array) ($claimBoundary['allowed_claims'] ?? []) === []
            || (array) ($claimBoundary['prohibited_claims'] ?? []) === []
            || trim((string) ($claimBoundary['required_disclaimer'] ?? '')) === '') {
            $issues[] = 'claim_boundary_incomplete:'.$url;
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<mixed>  $targets
     * @param  list<string>  $issues
     */
    private function validateTargetSetSha(array $evidence, array $targets, array &$issues): void
    {
        $material = array_map(static fn (mixed $target): array => [
            'canonical_url' => is_array($target) ? (string) ($target['canonical_url'] ?? '') : '',
            'content_sha256' => is_array($target) ? (string) data_get($target, 'current_authority.content_sha256', '') : '',
            'seo_sha256' => is_array($target) ? (string) data_get($target, 'current_authority.seo_sha256', '') : '',
        ], $targets);
        $actual = hash('sha256', $this->canonicalJson($material));
        $expected = (string) ($evidence['target_set_sha256'] ?? '');
        if (! $this->validHash($expected) || ! hash_equals($expected, $actual)) {
            $issues[] = 'target_set_sha_mismatch';
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $issues
     */
    private function validateManualReviewAndObservation(array $evidence, array &$issues): void
    {
        $review = (array) ($evidence['manual_review_gate'] ?? []);
        if (($review['status'] ?? null) !== 'pending'
            || ($review['reviewer_identity_required'] ?? null) !== true
            || ($review['reviewed_target_sha_required'] ?? null) !== true
            || ($review['bypass_allowed'] ?? null) !== false
            || array_values((array) ($review['required_checks'] ?? [])) !== self::REQUIRED_MANUAL_REVIEW_CHECKS) {
            $issues[] = 'manual_review_gate_invalid';
        }

        $observation = (array) ($evidence['observation_contract'] ?? []);
        if (($observation['anchor'] ?? null) !== 'future_manual_publish_receipt.published_at'
            || array_values((array) ($observation['windows'] ?? [])) !== ['D1', 'D7', 'D14', 'D28']
            || array_values((array) ($observation['metrics'] ?? [])) !== self::REQUIRED_OBSERVATION_METRICS
            || ($observation['second_batch_locked_until'] ?? null) !== 'D28_review_completed'
            || ($observation['automatic_second_batch_allowed'] ?? null) !== false) {
            $issues[] = 'observation_contract_invalid';
        }

        if ((int) data_get($evidence, 'query_owner.cross_target_conflict_count', -1) !== 0
            || data_get($evidence, 'query_owner.raw_query_persisted') !== false) {
            $issues[] = 'query_owner_contract_invalid';
        }
    }

    /**
     * @param  list<string>  $issues
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function blocked(array $issues, array $source = []): array
    {
        $issues = array_values(array_unique($issues === [] ? ['evidence_invalid'] : $issues));
        sort($issues);

        return [
            'schema_version' => self::OUTPUT_SCHEMA,
            'task' => self::TASK,
            'status' => 'blocked',
            'ok' => false,
            'dry_run' => true,
            'would_write' => false,
            'source_evidence_sha256' => $source['sha256'] ?? null,
            'issues' => $issues,
            'negative_guarantees' => [
                'database_write' => false,
                'cms_write' => false,
                'cms_publish' => false,
                'indexability_write' => false,
                'new_url' => false,
                'search_channel_enqueue' => false,
                'url_submission' => false,
                'scheduler' => false,
                'queue' => false,
                'deploy' => false,
            ],
        ];
    }

    private function safeArticleUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'fermatmind.com'
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && (bool) preg_match('#^/(?:en|zh)/articles/[a-z0-9]+(?:-[a-z0-9]+)*$#', (string) ($parts['path'] ?? ''));
    }

    private function safeSourceUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = mb_strtolower(trim((string) ($parts['host'] ?? ''), '[]'), 'UTF-8');

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && $host !== ''
            && $this->isPublicSourceHost($host)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && ! str_ends_with($host, 'fermatmind.com');
    }

    private function validHash(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $value);
    }

    private function validPageEvidenceId(string $value): bool
    {
        return (bool) preg_match('/^page_[a-f0-9]{32}$/', $value);
    }

    private function validPosition(mixed $value): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value)
            && $value >= 0;
    }

    private function isCredentialKey(string $normalizedKey): bool
    {
        $paddedKey = '_'.trim($normalizedKey, '_').'_';
        foreach (self::CREDENTIAL_KEYS as $credentialKey) {
            $collapsedCredentialKey = str_replace('_', '', $credentialKey);
            if (str_contains($paddedKey, '_'.$credentialKey.'_')
                || str_contains($paddedKey, '_'.$collapsedCredentialKey.'_')) {
                return true;
            }
        }

        return false;
    }

    private function isPublicSourceHost(string $host): bool
    {
        if (str_ends_with($host, '.')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        if (preg_match('/^[0-9.]+$/', $host) === 1) {
            return false;
        }
        $labels = explode('.', $host);
        if (array_all(
            $labels,
            static fn (string $label): bool => preg_match('/^(?:0x[0-9a-f]+|[0-9]+)$/', $label) === 1,
        )) {
            return false;
        }

        if (! str_contains($host, '.')) {
            return false;
        }

        foreach (['localhost', 'local', 'internal', 'home', 'lan'] as $privateSuffix) {
            if ($host === $privateSuffix || str_ends_with($host, '.'.$privateSuffix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function artifactSchemaKeysAreExact(array $payload, string $schema): bool
    {
        if ($schema === self::INPUT_SCHEMA) {
            if (! $this->hasExactKeys($payload, [
                'schema',
                'task',
                'batch_id',
                'observed_at',
                'scope',
                'gsc',
                'selection',
                'query_owner',
                'target_set_sha256',
                'targets',
                'manual_review_gate',
                'observation_contract',
                'negative_guarantees',
            ])
                || ! $this->hasExactKeys((array) ($payload['scope'] ?? []), [
                    'exact_existing_url_count',
                    'new_url_allowed',
                    'database_write_allowed',
                    'cms_write_allowed',
                    'publish_allowed',
                    'indexability_write_allowed',
                    'search_submit_allowed',
                    'scheduler_allowed',
                    'queue_allowed',
                    'deploy_allowed',
                ])
                || ! $this->hasExactKeys((array) ($payload['gsc'] ?? []), [
                    'data_origin',
                    'source_engine',
                    'property',
                    'search_type',
                    'current_window',
                    'previous_window',
                    'page_export',
                    'query_summary_artifact',
                    'page_cohort_artifact',
                    'formal_readmodel_gate',
                ])
                || ! $this->hasExactKeys((array) data_get($payload, 'gsc.current_window', []), ['start', 'end'])
                || ! $this->hasExactKeys((array) data_get($payload, 'gsc.previous_window', []), ['start', 'end'])
                || ! $this->hasExactKeys((array) data_get($payload, 'gsc.page_export', []), [
                    'zip_sha256',
                    'csv_sha256',
                    'total_row_count',
                ])
                || ! $this->hasExactKeys((array) data_get($payload, 'gsc.query_summary_artifact', []), [
                    'path',
                    'sha256',
                    'schema',
                    'raw_query_persisted',
                    'unkeyed_query_digest_persisted',
                ])
                || ! $this->hasExactKeys((array) data_get($payload, 'gsc.page_cohort_artifact', []), [
                    'path',
                    'sha256',
                    'schema',
                    'article_row_count',
                    'raw_url_persisted',
                    'unkeyed_url_digest_persisted',
                    'identifier_model',
                ])
                || ! $this->hasExactKeys((array) data_get($payload, 'gsc.formal_readmodel_gate', []), [
                    'status',
                    'required_data_origin',
                    'required_source_engine',
                    'required_row_source',
                    'required_data_state',
                    'required_article_cohort_coverage_count',
                    'actual_eligible_article_cohort_coverage_count',
                    'opportunity_queue_eligible',
                    'reason',
                ])
                || ! $this->hasExactKeys((array) ($payload['selection'] ?? []), [
                    'rule',
                    'eligibility_rule',
                    'eligible_scope',
                    'exact_count',
                    'page_export_article_row_count',
                ])
                || ! $this->hasExactKeys((array) ($payload['query_owner'] ?? []), [
                    'policy',
                    'conflict_state',
                    'cross_target_conflict_count',
                    'raw_query_persisted',
                    'unkeyed_query_digest_persisted',
                    'suppressed_target_count',
                    'suppressed_target_policy',
                ])
                || ! $this->hasExactKeys((array) ($payload['manual_review_gate'] ?? []), [
                    'status',
                    'reviewer_identity_required',
                    'reviewed_target_sha_required',
                    'bypass_allowed',
                    'required_checks',
                ])
                || ! $this->isStringList(data_get($payload, 'manual_review_gate.required_checks'))
                || ! $this->hasExactKeys((array) ($payload['observation_contract'] ?? []), [
                    'anchor',
                    'windows',
                    'metrics',
                    'second_batch_locked_until',
                    'automatic_second_batch_allowed',
                ])
                || ! $this->isStringList(data_get($payload, 'observation_contract.windows'))
                || ! $this->isStringList(data_get($payload, 'observation_contract.metrics'))
                || ! $this->hasExactKeys((array) ($payload['negative_guarantees'] ?? []), [
                    'database_write',
                    'cms_write',
                    'cms_publish',
                    'indexability_write',
                    'new_url',
                    'search_channel_enqueue',
                    'url_submission',
                    'scheduler',
                    'queue',
                    'deploy',
                ])) {
                return false;
            }

            foreach ((array) ($payload['targets'] ?? []) as $target) {
                if (! is_array($target)
                    || ! $this->hasExactKeys($target, [
                        'rank',
                        'article_id',
                        'locale',
                        'slug',
                        'canonical_url',
                        'page_evidence_id',
                        'current_authority',
                        'gsc_page',
                        'query_export',
                        'proposed_recovery',
                        'source_refs',
                        'claim_boundary',
                    ])
                    || ! $this->hasExactKeys((array) ($target['current_authority'] ?? []), [
                        'published_revision_id',
                        'status',
                        'is_public',
                        'is_indexable',
                        'sitemap_eligible',
                        'llms_eligible',
                        'review_state',
                        'last_reviewed_at',
                        'content_sha256',
                        'seo_sha256',
                        'public_api_http_status',
                    ])
                    || ! $this->hasExactKeys((array) ($target['gsc_page'] ?? []), [
                        'current_clicks',
                        'previous_clicks',
                        'click_delta',
                        'current_impressions',
                        'previous_impressions',
                        'impression_delta',
                        'current_position',
                        'previous_position',
                    ])
                    || ! $this->hasExactKeys((array) ($target['query_export'] ?? []), [
                        'zip_sha256',
                        'csv_sha256',
                        'raw_row_count',
                        'retained_query_count',
                        'excluded_site_operator_count',
                        'excluded_brand_or_mixed_count',
                        'evidence_state',
                    ])
                    || ! $this->hasExactKeys((array) ($target['proposed_recovery'] ?? []), [
                        'title_candidate',
                        'meta_description_candidate',
                        'visible_section_actions',
                    ])
                    || ! $this->isStringList(data_get($target, 'proposed_recovery.visible_section_actions'))
                    || ! $this->hasExactKeys((array) ($target['claim_boundary'] ?? []), [
                        'allowed_claims',
                        'prohibited_claims',
                        'required_disclaimer',
                    ])
                    || ! $this->isStringList(data_get($target, 'claim_boundary.allowed_claims'))
                    || ! $this->isStringList(data_get($target, 'claim_boundary.prohibited_claims'))
                    || ! array_is_list((array) ($target['source_refs'] ?? []))) {
                    return false;
                }
                foreach ((array) ($target['source_refs'] ?? []) as $sourceRef) {
                    if (! is_array($sourceRef)
                        || ! $this->hasExactKeys($sourceRef, ['id', 'url', 'authority_type'])) {
                        return false;
                    }
                }
            }

            return true;
        }

        if ($schema === self::QUERY_SCHEMA) {
            if (! $this->hasExactKeys($payload, [
                'schema',
                'task',
                'data_origin',
                'source_engine',
                'privacy_model',
                'raw_query_persisted',
                'unkeyed_query_digest_persisted',
                'exclusion_policy',
                'cross_target_owner_conflict_check',
                'target_summaries',
            ])
                || ! $this->isStringList($payload['exclusion_policy'] ?? null)
                || ! $this->hasExactKeys(
                    (array) ($payload['cross_target_owner_conflict_check'] ?? []),
                    ['performed_before_sanitization', 'conflict_count'],
                )) {
                return false;
            }

            foreach ((array) ($payload['target_summaries'] ?? []) as $summary) {
                if (! is_array($summary)
                    || ! $this->hasExactKeys($summary, ['retained_query_count', 'excluded', 'evidence_state'])
                    || ! $this->hasExactKeys(
                        (array) ($summary['excluded'] ?? []),
                        ['site_operator', 'brand_or_mixed'],
                    )) {
                    return false;
                }
            }

            return true;
        }

        if ($schema === self::PAGE_COHORT_SCHEMA) {
            if (! $this->hasExactKeys($payload, [
                'schema',
                'task',
                'data_origin',
                'source_engine',
                'search_type',
                'evidence_role',
                'formal_readmodel_gate_passed',
                'raw_url_persisted',
                'unkeyed_url_digest_persisted',
                'identifier_model',
                'current_window',
                'previous_window',
                'source_csv_sha256',
                'source_total_row_count',
                'article_row_count',
                'selection_rule',
                'eligibility_rule',
                'rows',
                'top_five_page_evidence_ids',
                'cutoff_attestation',
            ])
                || ! $this->hasExactKeys((array) ($payload['current_window'] ?? []), ['start', 'end'])
                || ! $this->hasExactKeys((array) ($payload['previous_window'] ?? []), ['start', 'end'])
                || ! $this->hasExactKeys((array) ($payload['cutoff_attestation'] ?? []), ['rank_5', 'rank_6'])
                || ! $this->isStringList($payload['top_five_page_evidence_ids'] ?? null)
                || ! array_is_list((array) ($payload['rows'] ?? []))) {
                return false;
            }

            $rowKeys = [
                'rank',
                'current_clicks',
                'previous_clicks',
                'click_delta',
                'current_impressions',
                'previous_impressions',
                'impression_delta',
                'current_position',
                'previous_position',
                'page_evidence_id',
            ];
            foreach ((array) ($payload['rows'] ?? []) as $row) {
                if (! is_array($row) || ! $this->hasExactKeys($row, $rowKeys)) {
                    return false;
                }
            }
            foreach ((array) ($payload['cutoff_attestation'] ?? []) as $row) {
                if (! is_array($row) || ! $this->hasExactKeys($row, $rowKeys)) {
                    return false;
                }
            }

            return true;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $previousWindow
     * @param  array<string, mixed>  $currentWindow
     */
    private function validComparisonWindows(
        array $previousWindow,
        array $currentWindow,
        string $observedAt,
    ): bool {
        $previousStart = $this->exactDate((string) ($previousWindow['start'] ?? ''));
        $previousEnd = $this->exactDate((string) ($previousWindow['end'] ?? ''));
        $currentStart = $this->exactDate((string) ($currentWindow['start'] ?? ''));
        $currentEnd = $this->exactDate((string) ($currentWindow['end'] ?? ''));

        $observed = $this->exactUtcTimestamp($observedAt);
        if ($observed === null
            || $observed > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            return false;
        }

        if ($previousStart === null || $previousEnd === null || $currentStart === null || $currentEnd === null
            || $previousStart > $previousEnd
            || $currentStart > $currentEnd
            || $currentStart != $previousEnd->modify('+1 day')
            || $currentEnd > $observed->setTime(0, 0)) {
            return false;
        }

        return $previousStart->diff($previousEnd)->days === $currentStart->diff($currentEnd)->days;
    }

    private function exactDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date instanceof \DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value
                ? $date
                : null;
    }

    private function exactUtcTimestamp(string $value): ?\DateTimeImmutable
    {
        $timestamp = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();

        return $timestamp instanceof \DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $timestamp->format('Y-m-d\TH:i:s\Z') === $value
                ? $timestamp
                : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $expectedKeys
     */
    private function hasExactKeys(array $payload, array $expectedKeys): bool
    {
        $actualKeys = array_keys($payload);
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }

    private function isStringList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && array_all($value, static fn (mixed $item): bool => is_string($item));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function forbiddenKeys(array $payload): array
    {
        $found = [];
        $walk = function (array $node) use (&$walk, &$found): void {
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $keyWithCamelBoundaries = preg_replace(
                        ['/(?<=[a-z0-9])(?=[A-Z])/', '/(?<=[A-Z])(?=[A-Z][a-z])/'],
                        '_',
                        rawurldecode($key),
                    );
                    $normalizedKey = preg_replace(
                        '/[^a-z0-9]+/',
                        '_',
                        mb_strtolower((string) $keyWithCamelBoundaries, 'UTF-8'),
                    );
                    if (is_string($normalizedKey)
                        && (in_array(trim($normalizedKey, '_'), self::FORBIDDEN_KEYS, true)
                            || $this->isCredentialKey($normalizedKey))) {
                        $found[] = $key;
                    }
                }
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($payload);

        return array_values(array_unique($found));
    }

    private function canonicalJson(mixed $value): string
    {
        return (string) json_encode(
            $this->sortRecursively($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
    }
}
