<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class CareerPlanCnProxyVisibleDetailPolicyAuthority extends Command
{
    private const EXPECTED_TARGET_TOTAL = 2786;

    private const EXPECTED_CN_PROXY_ROWS = 1663;

    /**
     * @var list<string>
     */
    private const REQUIRED_VISIBLE_DETAIL_PRECONDITIONS = [
        'explicit_product_policy_decision_for_cn_proxy_visible_detail',
        'cn_first_authority_source_evidence',
        'cn_visible_detail_schema_policy',
        'cn_display_asset_pipeline',
        'cn_directory_inclusion_gate',
        'cn_visible_live_acceptance',
    ];

    protected $signature = 'career:plan-cn-proxy-visible-detail-policy-authority
        {--scope= : CN proxy scope or slug matrix JSON artifact}
        {--public-owner-plan= : Reviewed noindex CN proxy public-owner plan JSON artifact}
        {--visible-gap= : Product visible gap or CN proxy policy scan summary JSON artifact}
        {--decision= : Optional explicit product policy decision JSON artifact}
        {--target-total=2786 : Target product visible detail total}
        {--json : Emit JSON output}
        {--output= : Optional JSON output artifact path}';

    protected $description = 'Plan CN proxy visible-detail publication policy authority without mutating production state.';

    public function handle(): int
    {
        try {
            $scopePath = $this->requiredPath('scope');
            $publicOwnerPlanPath = $this->requiredPath('public-owner-plan');
            $visibleGapPath = $this->requiredPath('visible-gap');
            $decisionPath = $this->optionalPath('decision');
            $targetTotal = (int) $this->option('target-total');

            $scopePayload = $this->readJson($scopePath, 'CN proxy scope');
            $publicOwnerPlan = $this->readJson($publicOwnerPlanPath, 'CN proxy public-owner plan');
            $visibleGap = $this->readJson($visibleGapPath, 'visible gap summary');
            $decision = $decisionPath === null ? [] : $this->readJson($decisionPath, 'product policy decision');

            $cnProxyRows = $this->cnProxyRowCount($scopePayload);
            $ownerSummary = $this->publicOwnerSummary($publicOwnerPlan);
            $surfaceSummary = $this->surfaceSummary($visibleGap);
            $decisionSummary = $this->decisionSummary($decision);
            $blockers = $this->blockers($targetTotal, $cnProxyRows, $ownerSummary, $surfaceSummary, $decisionSummary);
            $visibleDetailRequested = (bool) $decisionSummary['visible_detail_publication_requested'];
            $partitionAwareSelected = (bool) $decisionSummary['partition_aware_claim_selected'];

            $status = $blockers === [] ? 'pass' : 'blocked';
            $visibleDetailPublicationAllowed = $status === 'pass' && $visibleDetailRequested;

            $payload = [
                'schema_version' => 'career_cn_proxy_visible_detail_policy_authority.v1',
                'status' => $status,
                'command' => 'career:plan-cn-proxy-visible-detail-policy-authority',
                'read_only' => true,
                'writes_database' => false,
                'apply_allowed' => false,
                'rollout_allowed' => false,
                'candidate_prep_allowed' => false,
                'deploy_required' => false,
                'target_total' => $targetTotal,
                'expected_cn_proxy_rows' => self::EXPECTED_CN_PROXY_ROWS,
                'cn_proxy_rows' => $cnProxyRows,
                'scope_path' => $scopePath,
                'public_owner_plan_path' => $publicOwnerPlanPath,
                'visible_gap_path' => $visibleGapPath,
                'decision_path' => $decisionPath,
                'reviewed_noindex_public_owner' => $ownerSummary,
                'current_product_surface' => $surfaceSummary,
                'product_policy_decision' => $decisionSummary,
                'required_visible_detail_preconditions' => self::REQUIRED_VISIBLE_DETAIL_PRECONDITIONS,
                'visible_detail_publication_requested' => $visibleDetailRequested,
                'partition_aware_claim_selected' => $partitionAwareSelected,
                'visible_detail_publication_allowed' => $visibleDetailPublicationAllowed,
                'safe_claim_scope' => $visibleDetailPublicationAllowed
                    ? 'product_visible_detail_publication'
                    : ($partitionAwareSelected ? 'partition_accounted_not_visible_detail' : 'insufficient_product_policy_authority'),
                'blocked_claims' => $visibleDetailPublicationAllowed ? [] : [
                    '2786_visible_directory_members',
                    '2786_visible_detail_pages',
                    '2786_detail_indexable_pages',
                ],
                'blockers' => $blockers,
                'next_required_action' => $this->nextRequiredAction($blockers, $visibleDetailRequested, $partitionAwareSelected),
            ];

            $this->writeOutputArtifact($payload);
            $this->emitPayload($payload);

            return $status === 'pass' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $throwable) {
            $payload = [
                'schema_version' => 'career_cn_proxy_visible_detail_policy_authority.v1',
                'status' => 'failed',
                'command' => 'career:plan-cn-proxy-visible-detail-policy-authority',
                'read_only' => true,
                'writes_database' => false,
                'apply_allowed' => false,
                'rollout_allowed' => false,
                'candidate_prep_allowed' => false,
                'message' => $throwable->getMessage(),
                'blockers' => [
                    [
                        'reason' => 'command_or_artifact_error',
                        'context' => [
                            'message' => $throwable->getMessage(),
                        ],
                    ],
                ],
                'next_required_action' => 'REPAIR_COMMAND_OR_ARTIFACT_INPUT',
            ];

            $this->writeOutputArtifact($payload);
            $this->emitPayload($payload, error: true);

            return self::FAILURE;
        }
    }

    private function requiredPath(string $option): string
    {
        $path = trim((string) $this->option($option));
        if ($path === '') {
            throw new \RuntimeException($option.' artifact path is required');
        }
        if (! is_file($path)) {
            throw new \RuntimeException($option.' artifact not found: '.$path);
        }

        return $path;
    }

    private function optionalPath(string $option): ?string
    {
        $value = $this->option($option);
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $path = trim((string) $value);
        if (! is_file($path)) {
            throw new \RuntimeException($option.' artifact not found: '.$path);
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path, string $label): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new \RuntimeException($label.' artifact is not valid JSON: '.$path);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cnProxyRowCount(array $payload): int
    {
        $rows = data_get($payload, 'rows');
        if (! is_array($rows)) {
            $rows = data_get($payload, 'cn_proxy_scope.rows');
        }
        if (! is_array($rows)) {
            $rows = data_get($payload, 'slug_matrix');
        }

        if (is_array($rows)) {
            $count = 0;
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $partition = (string) ($row['partition'] ?? $row['public_resolution_type'] ?? $row['recommended_resolution'] ?? '');
                $slug = (string) ($row['slug'] ?? $row['source_slug'] ?? '');
                if (str_contains($partition, 'cn_proxy') || str_starts_with($slug, 'cn-') || str_starts_with($slug, 'cn-proxy-')) {
                    $count++;
                }
            }

            return $count > 0 ? $count : count($rows);
        }

        foreach ([
            'cn_proxy_rows',
            'current_counts.cn_proxy_noindex_public_owner',
            'visible_counts_after_1434.cn_proxy_public_owner_partition',
            'count',
        ] as $path) {
            $value = data_get($payload, $path);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function publicOwnerSummary(array $payload): array
    {
        $planReady = $this->boolAtAny($payload, [
            'public_owner_plan_ready',
            'summary.public_owner_plan_ready',
        ]);
        $reviewComplete = $this->boolAtAny($payload, [
            'reviewed_trust_manifest_complete',
            'reviewed_manifest_complete',
            'summary.reviewed_trust_manifest_complete',
        ]);

        $rows = $this->intAtAny($payload, [
            'public_cn_proxy_page_rows',
            'cn_proxy_rows',
            'summary.cn_proxy_rows',
            'reviewed_manifest_claims',
        ]);
        $noindexDefault = $this->boolAtAny($payload, [
            'noindex_default',
            'summary.noindex_default',
        ]);

        return [
            'plan_ready' => $planReady,
            'reviewed_trust_manifest_complete' => $reviewComplete,
            'public_cn_proxy_page_rows' => $rows,
            'noindex_default' => $noindexDefault,
            'indexable_cn_proxy_rows' => $this->intAtAny($payload, ['indexable_CN_proxy_rows', 'indexable_cn_proxy_rows']) ?? 0,
            'sitemap_cn_urls' => $this->intAtAny($payload, ['sitemap_CN_urls', 'sitemap_cn_urls']) ?? 0,
            'llms_cn_urls' => $this->intAtAny($payload, ['llms_CN_urls', 'llms_cn_urls']) ?? 0,
            'llms_full_cn_urls' => $this->intAtAny($payload, ['llms_full_CN_urls', 'llms_full_cn_urls']) ?? 0,
            'display_asset_delta' => $this->intAtAny($payload, ['display_asset_delta', 'career_job_display_assets_delta']) ?? 0,
            'occupations_delta' => $this->intAtAny($payload, ['occupations_delta']) ?? 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function surfaceSummary(array $payload): array
    {
        return [
            'source_assets' => $this->intAtAny($payload, [
                'current_counts.source_assets',
                'visible_counts_after_1434.all_source_slugs',
                'visible_counts.source_assets',
                'source_assets',
            ]),
            'visible_detail_indexable' => $this->intAtAny($payload, [
                'current_counts.visible_detail_indexable',
                'visible_counts_after_1434.backend_public_detail_indexable_count',
                'visible_counts.backend_public_detail_indexable_count',
                'detail_indexable_pages',
            ]),
            'cn_proxy_noindex_public_owner' => $this->intAtAny($payload, [
                'current_counts.cn_proxy_noindex_public_owner',
                'visible_counts_after_1434.cn_proxy_public_owner_partition',
                'visible_counts.cn_proxy_public_owner_partition',
                'cn_proxy_rows',
            ]),
            'software_manual_hold' => $this->intAtAny($payload, [
                'current_counts.software_manual_hold',
                'visible_counts_after_1434.software_manual_hold',
                'visible_counts.software_manual_hold',
            ]),
            'gap_to_visible_detail' => $this->intAtAny($payload, [
                'current_counts.gap_to_2786_visible_detail',
                'visible_counts_after_1434.detail_indexable_gap_to_2786',
                'visible_counts.detail_indexable_gap_to_2786',
                'gap_to_2786_visible_detail',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function decisionSummary(array $payload): array
    {
        $decisionId = $this->nullableString(data_get($payload, 'decision_id'))
            ?? $this->nullableString(data_get($payload, 'decision'))
            ?? $this->nullableString(data_get($payload, 'selected_decision'))
            ?? $this->nullableString(data_get($payload, 'recommended_decision'));

        return [
            'decision_id' => $decisionId,
            'decision_artifact_present' => $payload !== [],
            'visible_detail_publication_requested' => $decisionId === 'PURSUE_CN_PROXY_VISIBLE_DETAIL_PUBLICATION',
            'partition_aware_claim_selected' => $decisionId === 'KEEP_PARTITION_AWARE_PRODUCT_CLAIM',
            'human_review_required' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $owner
     * @param  array<string, mixed>  $surface
     * @param  array<string, mixed>  $decision
     * @return list<array{reason: string, context: array<string, mixed>}>
     */
    private function blockers(int $targetTotal, int $cnProxyRows, array $owner, array $surface, array $decision): array
    {
        $blockers = [];

        if ($targetTotal !== self::EXPECTED_TARGET_TOTAL) {
            $blockers[] = $this->blocker('target_total_mismatch', [
                'expected' => self::EXPECTED_TARGET_TOTAL,
                'actual' => $targetTotal,
            ]);
        }

        if ($cnProxyRows !== self::EXPECTED_CN_PROXY_ROWS) {
            $blockers[] = $this->blocker('cn_proxy_row_count_mismatch', [
                'expected' => self::EXPECTED_CN_PROXY_ROWS,
                'actual' => $cnProxyRows,
            ]);
        }

        if (($owner['plan_ready'] ?? false) !== true || ($owner['reviewed_trust_manifest_complete'] ?? false) !== true) {
            $blockers[] = $this->blocker('reviewed_noindex_public_owner_plan_not_ready', $owner);
        }

        foreach ([
            'indexable_cn_proxy_rows',
            'sitemap_cn_urls',
            'llms_cn_urls',
            'llms_full_cn_urls',
            'display_asset_delta',
            'occupations_delta',
        ] as $field) {
            if ((int) ($owner[$field] ?? 0) !== 0) {
                $blockers[] = $this->blocker('reviewed_noindex_public_owner_plan_unsafe_'.$field, [
                    'actual' => (int) ($owner[$field] ?? 0),
                ]);
            }
        }

        if (($surface['source_assets'] ?? null) !== self::EXPECTED_TARGET_TOTAL) {
            $blockers[] = $this->blocker('source_asset_count_mismatch', [
                'expected' => self::EXPECTED_TARGET_TOTAL,
                'actual' => $surface['source_assets'] ?? null,
            ]);
        }

        if (($surface['cn_proxy_noindex_public_owner'] ?? null) !== self::EXPECTED_CN_PROXY_ROWS) {
            $blockers[] = $this->blocker('cn_proxy_surface_count_mismatch', [
                'expected' => self::EXPECTED_CN_PROXY_ROWS,
                'actual' => $surface['cn_proxy_noindex_public_owner'] ?? null,
            ]);
        }

        if (($decision['decision_artifact_present'] ?? false) !== true) {
            $blockers[] = $this->blocker('product_policy_decision_missing', [
                'required_decisions' => [
                    'KEEP_PARTITION_AWARE_PRODUCT_CLAIM',
                    'PURSUE_CN_PROXY_VISIBLE_DETAIL_PUBLICATION',
                ],
            ]);

            return $blockers;
        }

        if (($decision['partition_aware_claim_selected'] ?? false) === true) {
            return $blockers;
        }

        if (($decision['visible_detail_publication_requested'] ?? false) !== true) {
            $blockers[] = $this->blocker('unsupported_product_policy_decision', [
                'decision_id' => $decision['decision_id'] ?? null,
            ]);

            return $blockers;
        }

        foreach (array_slice(self::REQUIRED_VISIBLE_DETAIL_PRECONDITIONS, 1) as $precondition) {
            $blockers[] = $this->blocker($precondition.'_missing', [
                'message' => 'Reviewed noindex CN proxy public-owner evidence does not authorize indexable visible detail publication.',
            ]);
        }

        return $blockers;
    }

    private function nextRequiredAction(array $blockers, bool $visibleDetailRequested, bool $partitionAwareSelected): string
    {
        if ($blockers === [] && $partitionAwareSelected) {
            return 'KEEP_PARTITION_AWARE_PRODUCT_CLAIM';
        }

        if ($visibleDetailRequested) {
            return 'REPAIR_CN_PROXY_CN_FIRST_DISPLAY_ASSET_PIPELINE_1';
        }

        return 'PRODUCT_POLICY_DECISION_CN_PROXY_VISIBLE_DETAIL_1';
    }

    /**
     * @param  list<string>  $paths
     */
    private function intAtAny(array $payload, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $paths
     */
    private function boolAtAny(array $payload, array $paths): ?bool
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (bool) $value;
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{reason: string, context: array<string, mixed>}
     */
    private function blocker(string $reason, array $context): array
    {
        return [
            'reason' => $reason,
            'context' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeOutputArtifact(array $payload): void
    {
        $outputPath = $this->option('output');
        if ($outputPath === null || trim((string) $outputPath) === '') {
            return;
        }

        $path = trim((string) $outputPath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitPayload(array $payload, bool $error = false): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $line = ($payload['status'] ?? 'unknown').': '.($payload['next_required_action'] ?? 'no_next_action');
        if ($error) {
            $this->error($line);

            return;
        }

        $this->info($line);
    }
}
