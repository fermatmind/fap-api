<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerDetailReadyTargetAuthority
{
    public const SCHEMA_VERSION = 'career_detail_ready_1048_target_authority.v1';

    public const TARGET_KEY = 'detail_ready_1048';

    public const CURRENT_PUBLIC_DETAIL_TOTAL = 30;

    public const TARGET_PUBLIC_TOTAL = 1048;

    public const READY_NOT_PUBLIC_DELTA = 1018;

    public const RAW_OCCUPATION_ASSET_TOTAL = 2786;

    public const EXCLUDED_RAW_ASSET_TOTAL = 2289;

    public const MANUAL_HOLD_SLUGS = [
        'software-developers',
    ];

    /**
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    public function target(array $locales = ['en', 'zh']): array
    {
        $locales = $this->normalizeLocales($locales);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'target_key' => self::TARGET_KEY,
            'target_public_total' => self::TARGET_PUBLIC_TOTAL,
            'current_public_detail_total' => self::CURRENT_PUBLIC_DETAIL_TOTAL,
            'ready_not_currently_public_delta' => self::READY_NOT_PUBLIC_DELTA,
            'locale_policy' => [
                'locales' => $locales,
                'expected_locale_rows' => self::TARGET_PUBLIC_TOTAL * count($locales),
            ],
            'publication_scope' => [
                'product_visible_detail_pages' => true,
                'public_runtime_authority_required' => true,
                'sitemap_llms_authority_required' => true,
                'runtime_projection_artifact_required' => true,
                'candidate_prep_apply_allowed' => false,
                'rollout_apply_allowed' => false,
                'production_deploy_allowed' => false,
            ],
            'partition_boundary' => [
                'is_2786_partition_accounting' => false,
                'raw_occupation_asset_total' => self::RAW_OCCUPATION_ASSET_TOTAL,
                'excluded_raw_asset_total' => self::EXCLUDED_RAW_ASSET_TOTAL,
                'raw_assets_are_not_publication_authority' => true,
                'do_not_claim_2786_visible_jobs' => true,
                'do_not_publish_excluded_raw_assets' => true,
            ],
            'manual_hold_policy' => [
                'slugs' => self::MANUAL_HOLD_SLUGS,
                'release_requires_explicit_decision_artifact' => true,
                'must_not_force_enable' => true,
                'must_not_enter_candidate_prep_without_release_decision' => true,
                'must_not_enter_sitemap_llms_or_public_routes_without_release_decision' => true,
            ],
            'cn_proxy_policy' => [
                'cn_proxy_rows_are_not_detail_ready_publication_authority' => true,
                'preserve_noindex_noncanonical_policy' => true,
                'must_not_count_toward_product_visible_detail_claim' => true,
            ],
            'claim_guardrails' => [
                'claim_policy_version' => 'career_detail_ready_1048_product_visible_claim.v1',
                'visible_detail_claim_requires' => [
                    'dataset_member_count' => self::TARGET_PUBLIC_TOTAL,
                    'career_jobs_api_item_count' => self::TARGET_PUBLIC_TOTAL,
                    'detail_ready_count' => self::TARGET_PUBLIC_TOTAL,
                    'public_detail_indexable_count' => self::TARGET_PUBLIC_TOTAL,
                    'expected_locale_rows' => self::TARGET_PUBLIC_TOTAL * count($locales),
                    'no_noindex_404_or_redirect_source_urls_in_sitemap_llms' => true,
                ],
                'unsafe_claims' => [
                    'all_2786_jobs_are_public',
                    'all_2786_detail_pages_are_indexable',
                    'excluded_raw_assets_are_public',
                    'software_developers_is_public_without_release_decision',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    public function productVisibleClaim(array $evidence, array $locales = ['en', 'zh']): array
    {
        $locales = $this->normalizeLocales($locales);
        $expectedLocaleRows = self::TARGET_PUBLIC_TOTAL * count($locales);
        $datasetMemberCount = $this->intAtAny($evidence, [
            'dataset.member_count',
            'collection_summary.member_count',
            'product_surface.directory_member_count',
            'observed_public_directory_member_count',
        ]);
        $careerJobsItemCount = $this->intAtAny($evidence, [
            'career_jobs.item_count',
            'career_job_list.item_count',
            'product_surface.career_jobs_item_count',
            'observed_job_items_count',
        ]);
        $detailReadyCount = $this->intAtAny($evidence, [
            'detail_ready.count',
            'product_surface.detail_ready_count',
            'public_detail_indexable_count',
            'observed_detail_ready_count',
        ]);
        $publicDetailIndexableCount = $this->intAtAny($evidence, [
            'product_surface.public_detail_indexable_count',
            'collection_summary.public_detail_indexable_count',
            'public_detail_indexable_count',
        ]);
        $foundPublishedLocaleRows = $this->intAtAny($evidence, [
            'found_published',
            'acceptance_summary.found_published',
            'canonical_public_locale_rows',
        ]);
        $releaseGatePassCount = $this->intAtAny($evidence, [
            'release_gate.pass_count',
            'release_gate_pass_count',
            'acceptance_summary.release_gate_pass_count',
        ]);
        $badUrlCount = $this->intAtAny($evidence, [
            'sitemap_llms.bad_url_count',
            'bad_url_count',
            'acceptance_summary.bad_url_count',
        ]) ?? 0;
        $partitionAccountingTotal = $this->intAtAny($evidence, [
            'partition_accounting.final_public_accounted_total',
            'final_public_accounted_total',
        ]);

        $visibleDetailClaimAllowed = $datasetMemberCount === self::TARGET_PUBLIC_TOTAL
            && $careerJobsItemCount === self::TARGET_PUBLIC_TOTAL
            && $detailReadyCount === self::TARGET_PUBLIC_TOTAL
            && $publicDetailIndexableCount === self::TARGET_PUBLIC_TOTAL
            && $foundPublishedLocaleRows === $expectedLocaleRows
            && $releaseGatePassCount === $expectedLocaleRows
            && $badUrlCount === 0;

        return [
            'claim_policy_version' => 'career_detail_ready_1048_product_visible_claim.v1',
            'target_key' => self::TARGET_KEY,
            'visible_detail_claim_allowed' => $visibleDetailClaimAllowed,
            'partition_accounting_claim_allowed' => false,
            'safe_claim_scope' => $visibleDetailClaimAllowed
                ? 'product_visible_detail_ready_1048'
                : ($partitionAccountingTotal === self::RAW_OCCUPATION_ASSET_TOTAL
                    ? 'partition_accounted_not_product_visible'
                    : 'insufficient_product_visible_evidence'),
            'claimable_counts' => [
                'dataset_member_count' => $datasetMemberCount,
                'career_jobs_item_count' => $careerJobsItemCount,
                'detail_ready_count' => $detailReadyCount,
                'public_detail_indexable_count' => $publicDetailIndexableCount,
                'found_published_locale_rows' => $foundPublishedLocaleRows,
                'release_gate_pass_count' => $releaseGatePassCount,
                'bad_url_count' => $badUrlCount,
                'partition_accounting_total' => $partitionAccountingTotal,
            ],
        ];
    }

    public function supportsTarget(string $targetKey): bool
    {
        return $targetKey === self::TARGET_KEY;
    }

    /**
     * @param  list<string>  $locales
     * @return list<string>
     */
    private function normalizeLocales(array $locales): array
    {
        $normalized = [];
        foreach ($locales as $locale) {
            $value = strtolower(trim($locale));
            if ($value !== '') {
                $normalized[$value] = true;
            }
        }

        $keys = array_keys($normalized);
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $paths
     */
    private function intAtAny(array $payload, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_int($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
