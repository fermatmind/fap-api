<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerFullVisiblePublicationGate
{
    public function appliesTo(int $targetPublicTotal): bool
    {
        return in_array($targetPublicTotal, [1048, 2786], true);
    }

    /**
     * @param  array<string, mixed>  $liveAcceptance
     * @return array<string, mixed>
     */
    public function summary(array $liveAcceptance, int $targetPublicTotal, int $localeCount): array
    {
        $expectedLocaleRows = $targetPublicTotal * $localeCount;

        return [
            'required' => $this->appliesTo($targetPublicTotal),
            'target_public_total' => $targetPublicTotal,
            'expected_locale_rows' => $expectedLocaleRows,
            'directory_member_count' => $this->directoryMemberCount($liveAcceptance),
            'career_jobs_item_count' => $this->careerJobsItemCount($liveAcceptance),
            'detail_ready_count' => $this->detailReadyCount($liveAcceptance),
            'public_detail_indexable_count' => $this->publicDetailIndexableCount($liveAcceptance),
            'canonical_public_slug_count' => $this->intAtAny($liveAcceptance, [
                'canonical_public_slug_count',
                'product_surface.canonical_public_slug_count',
                'validation.full_visible_publication_gate.canonical_public_slug_count',
            ]),
            'found_published_locale_rows' => $this->foundPublishedLocaleRows($liveAcceptance),
            'release_gate_pass_count' => $this->releaseGatePassCount($liveAcceptance),
            'forbidden_exposure_counts' => $this->forbiddenExposureCounts($liveAcceptance),
            'forbidden_exposure_evidence_present' => $this->forbiddenExposureEvidencePresent($liveAcceptance),
            'product_claim' => $this->productClaim($liveAcceptance, $targetPublicTotal, $expectedLocaleRows),
        ];
    }

    /**
     * @param  array<string, mixed>  $liveAcceptance
     * @return list<array{reason: string, context: array<string, mixed>}>
     */
    public function blockers(array $liveAcceptance, int $targetPublicTotal, int $localeCount): array
    {
        if (! $this->appliesTo($targetPublicTotal)) {
            return [];
        }

        $summary = $this->summary($liveAcceptance, $targetPublicTotal, $localeCount);
        $expectedLocaleRows = $targetPublicTotal * $localeCount;
        $blockers = [];

        $this->requireCount(
            blockers: $blockers,
            reasonPrefix: 'product_directory_member_count',
            expected: $targetPublicTotal,
            actual: $summary['directory_member_count'],
        );
        $this->requireCount(
            blockers: $blockers,
            reasonPrefix: 'product_career_jobs_item_count',
            expected: $targetPublicTotal,
            actual: $summary['career_jobs_item_count'],
        );
        $this->requireCount(
            blockers: $blockers,
            reasonPrefix: 'product_detail_ready_count',
            expected: $targetPublicTotal,
            actual: $summary['detail_ready_count'],
        );
        $this->requireCount(
            blockers: $blockers,
            reasonPrefix: 'product_public_detail_indexable_count',
            expected: $targetPublicTotal,
            actual: $summary['public_detail_indexable_count'],
        );
        $this->requireCount(
            blockers: $blockers,
            reasonPrefix: 'product_found_published_locale_rows',
            expected: $expectedLocaleRows,
            actual: $summary['found_published_locale_rows'],
        );
        $this->requireCount(
            blockers: $blockers,
            reasonPrefix: 'product_release_gate_pass_count',
            expected: $expectedLocaleRows,
            actual: $summary['release_gate_pass_count'],
        );

        $canonicalPublicSlugCount = $summary['canonical_public_slug_count'];
        if ($canonicalPublicSlugCount !== null && $canonicalPublicSlugCount !== $targetPublicTotal) {
            $blockers[] = $this->blocker('product_canonical_public_slug_count_mismatch', [
                'expected' => $targetPublicTotal,
                'actual' => $canonicalPublicSlugCount,
            ]);
        }

        foreach ($summary['forbidden_exposure_counts'] as $key => $count) {
            if ($count > 0) {
                $blockers[] = $this->blocker('product_forbidden_'.$key.'_present', [
                    'actual' => $count,
                ]);
            }
        }

        if ($blockers !== [] && $this->intAtAny($liveAcceptance, [
            'partition_accounting.final_public_accounted_total',
            'final_public_accounted_total',
        ]) === $targetPublicTotal) {
            $blockers[] = $this->blocker('partition_accounting_not_product_publication_evidence', [
                'target_public_total' => $targetPublicTotal,
                'message' => 'Final partition accounting cannot prove the public directory member count or detail-ready count.',
            ]);
        }

        return $blockers;
    }

    /**
     * @param  list<array{reason: string, context: array<string, mixed>}>  $blockers
     */
    private function requireCount(array &$blockers, string $reasonPrefix, int $expected, ?int $actual): void
    {
        if ($actual === null) {
            $blockers[] = $this->blocker($reasonPrefix.'_missing', ['expected' => $expected]);

            return;
        }

        if ($actual !== $expected) {
            $blockers[] = $this->blocker($reasonPrefix.'_mismatch', [
                'expected' => $expected,
                'actual' => $actual,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function directoryMemberCount(array $payload): ?int
    {
        return $this->intAtAny($payload, [
            'product_surface.directory_member_count',
            'product_surface.member_count',
            'validation.full_visible_publication_gate.directory_member_count',
            'directory.member_count',
            'career_directory.member_count',
            'collection_summary.member_count',
            'api_collection_summary.member_count',
            'observed_public_directory_member_count',
            'observed_dataset_members_len',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function careerJobsItemCount(array $payload): ?int
    {
        return $this->intAtAny($payload, [
            'product_surface.career_jobs_item_count',
            'product_surface.job_items_count',
            'validation.full_visible_publication_gate.career_jobs_item_count',
            'career_jobs.item_count',
            'career_job_list.item_count',
            'job_items_count',
            'observed_job_items_count',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function detailReadyCount(array $payload): ?int
    {
        return $this->intAtAny($payload, [
            'product_surface.detail_ready_count',
            'product_surface.visible_detail_count',
            'product_surface.public_detail_indexable_count',
            'validation.full_visible_publication_gate.detail_ready_count',
            'career_jobs.detail_ready_count',
            'collection_summary.public_detail_indexable_count',
            'api_collection_summary.public_detail_indexable_count',
            'public_detail_indexable_count',
            'observed_detail_ready_count',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publicDetailIndexableCount(array $payload): ?int
    {
        return $this->intAtAny($payload, [
            'product_surface.public_detail_indexable_count',
            'validation.full_visible_publication_gate.public_detail_indexable_count',
            'collection_summary.public_detail_indexable_count',
            'api_collection_summary.public_detail_indexable_count',
            'public_detail_indexable_count',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function foundPublishedLocaleRows(array $payload): ?int
    {
        return $this->intAtAny($payload, [
            'found_published',
            'projection_truth.found_published',
            'acceptance_summary.found_published',
            'validation.full_visible_publication_gate.found_published_locale_rows',
            'canonical_public_locale_rows',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function releaseGatePassCount(array $payload): ?int
    {
        return $this->intAtAny($payload, [
            'release_gate_pass_count',
            'release_gate.pass_count',
            'acceptance_summary.release_gate_pass_count',
            'validation.full_visible_publication_gate.release_gate_pass_count',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function productClaim(array $payload, int $targetPublicTotal, int $expectedLocaleRows): array
    {
        $directoryMemberCount = $this->directoryMemberCount($payload);
        $careerJobsItemCount = $this->careerJobsItemCount($payload);
        $detailReadyCount = $this->detailReadyCount($payload);
        $publicDetailIndexableCount = $this->publicDetailIndexableCount($payload);
        $foundPublishedLocaleRows = $this->foundPublishedLocaleRows($payload);
        $releaseGatePassCount = $this->releaseGatePassCount($payload);
        $forbiddenExposureCounts = $this->forbiddenExposureCounts($payload);
        $forbiddenExposureEvidencePresent = $this->forbiddenExposureEvidencePresent($payload);
        $partitionAccountingTotal = $this->intAtAny($payload, [
            'partition_accounting.final_public_accounted_total',
            'final_public_accounted_total',
        ]);
        $visibleDetailClaimAllowed = $this->appliesTo($targetPublicTotal)
            && $directoryMemberCount === $targetPublicTotal
            && $careerJobsItemCount === $targetPublicTotal
            && $detailReadyCount === $targetPublicTotal
            && $publicDetailIndexableCount === $targetPublicTotal
            && $foundPublishedLocaleRows === $expectedLocaleRows
            && $releaseGatePassCount === $expectedLocaleRows
            && array_sum($forbiddenExposureCounts) === 0;

        return [
            'claim_policy_version' => 'career_product_visible_claim.v1',
            'target_public_total' => $targetPublicTotal,
            'expected_locale_rows' => $expectedLocaleRows,
            'visible_detail_claim_allowed' => $visibleDetailClaimAllowed,
            'partition_accounting_claim_allowed' => $partitionAccountingTotal === $targetPublicTotal,
            'safe_claim_scope' => $visibleDetailClaimAllowed
                ? 'product_visible_detail_publication'
                : ($partitionAccountingTotal === $targetPublicTotal
                    ? 'partition_accounted_not_visible_detail'
                    : 'insufficient_product_evidence'),
            'claimable_counts' => [
                'directory_member_count' => $directoryMemberCount,
                'career_jobs_item_count' => $careerJobsItemCount,
                'detail_ready_count' => $detailReadyCount,
                'public_detail_indexable_count' => $publicDetailIndexableCount,
                'found_published_locale_rows' => $foundPublishedLocaleRows,
                'release_gate_pass_count' => $releaseGatePassCount,
                'partition_accounting_total' => $partitionAccountingTotal,
                'forbidden_exposure_counts' => $forbiddenExposureCounts,
                'forbidden_exposure_evidence_present' => $forbiddenExposureEvidencePresent,
            ],
            'blocked_claims' => $visibleDetailClaimAllowed ? [] : [
                $targetPublicTotal.'_visible_directory_members',
                $targetPublicTotal.'_visible_detail_pages',
                $targetPublicTotal.'_detail_indexable_pages',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int>
     */
    private function forbiddenExposureCounts(array $payload): array
    {
        return [
            'sitemap_noindex_urls' => $this->countAtAny($payload, [
                'product_surface.sitemap_noindex_url_count',
                'product_surface.sitemap_noindex_urls',
                'sitemap.noindex_url_count',
                'sitemap.noindex_urls',
                'forbidden_exposure.sitemap_noindex_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.sitemap_noindex_urls',
            ]) ?? 0,
            'sitemap_404_urls' => $this->countAtAny($payload, [
                'product_surface.sitemap_404_url_count',
                'product_surface.sitemap_404_urls',
                'sitemap.not_found_url_count',
                'sitemap.404_urls',
                'forbidden_exposure.sitemap_404_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.sitemap_404_urls',
            ]) ?? 0,
            'sitemap_redirect_source_urls' => $this->countAtAny($payload, [
                'product_surface.sitemap_redirect_source_url_count',
                'product_surface.sitemap_redirect_source_urls',
                'sitemap.redirect_source_url_count',
                'sitemap.redirect_source_urls',
                'forbidden_exposure.sitemap_redirect_source_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.sitemap_redirect_source_urls',
            ]) ?? 0,
            'llms_noindex_urls' => $this->countAtAny($payload, [
                'product_surface.llms_noindex_url_count',
                'product_surface.llms_noindex_urls',
                'llms.noindex_url_count',
                'llms.noindex_urls',
                'forbidden_exposure.llms_noindex_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.llms_noindex_urls',
            ]) ?? 0,
            'llms_404_urls' => $this->countAtAny($payload, [
                'product_surface.llms_404_url_count',
                'product_surface.llms_404_urls',
                'llms.not_found_url_count',
                'llms.404_urls',
                'forbidden_exposure.llms_404_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.llms_404_urls',
            ]) ?? 0,
            'llms_redirect_source_urls' => $this->countAtAny($payload, [
                'product_surface.llms_redirect_source_url_count',
                'product_surface.llms_redirect_source_urls',
                'llms.redirect_source_url_count',
                'llms.redirect_source_urls',
                'forbidden_exposure.llms_redirect_source_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.llms_redirect_source_urls',
            ]) ?? 0,
            'llms_full_noindex_urls' => $this->countAtAny($payload, [
                'product_surface.llms_full_noindex_url_count',
                'product_surface.llms_full_noindex_urls',
                'llms_full.noindex_url_count',
                'llms_full.noindex_urls',
                'forbidden_exposure.llms_full_noindex_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.llms_full_noindex_urls',
            ]) ?? 0,
            'llms_full_404_urls' => $this->countAtAny($payload, [
                'product_surface.llms_full_404_url_count',
                'product_surface.llms_full_404_urls',
                'llms_full.not_found_url_count',
                'llms_full.404_urls',
                'forbidden_exposure.llms_full_404_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.llms_full_404_urls',
            ]) ?? 0,
            'llms_full_redirect_source_urls' => $this->countAtAny($payload, [
                'product_surface.llms_full_redirect_source_url_count',
                'product_surface.llms_full_redirect_source_urls',
                'llms_full.redirect_source_url_count',
                'llms_full.redirect_source_urls',
                'forbidden_exposure.llms_full_redirect_source_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.llms_full_redirect_source_urls',
            ]) ?? 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{sitemap: bool, llms: bool, llms_full: bool}
     */
    private function forbiddenExposureEvidencePresent(array $payload): array
    {
        return [
            'sitemap' => $this->hasAny($payload, [
                'product_surface.sitemap_noindex_url_count',
                'product_surface.sitemap_noindex_urls',
                'product_surface.sitemap_404_url_count',
                'product_surface.sitemap_404_urls',
                'product_surface.sitemap_redirect_source_url_count',
                'product_surface.sitemap_redirect_source_urls',
                'sitemap.noindex_url_count',
                'sitemap.noindex_urls',
                'sitemap.not_found_url_count',
                'sitemap.404_urls',
                'sitemap.redirect_source_url_count',
                'sitemap.redirect_source_urls',
                'forbidden_exposure.sitemap_noindex_urls',
                'forbidden_exposure.sitemap_404_urls',
                'forbidden_exposure.sitemap_redirect_source_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.sitemap_noindex_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.sitemap_404_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.sitemap_redirect_source_urls',
            ]),
            'llms' => $this->hasAny($payload, [
                'product_surface.llms_noindex_url_count',
                'product_surface.llms_noindex_urls',
                'product_surface.llms_404_url_count',
                'product_surface.llms_404_urls',
                'product_surface.llms_redirect_source_url_count',
                'product_surface.llms_redirect_source_urls',
                'llms.noindex_url_count',
                'llms.noindex_urls',
                'llms.not_found_url_count',
                'llms.404_urls',
                'llms.redirect_source_url_count',
                'llms.redirect_source_urls',
                'forbidden_exposure.llms_noindex_urls',
                'forbidden_exposure.llms_404_urls',
                'forbidden_exposure.llms_redirect_source_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.llms_noindex_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.llms_404_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.llms_redirect_source_urls',
            ]),
            'llms_full' => $this->hasAny($payload, [
                'product_surface.llms_full_noindex_url_count',
                'product_surface.llms_full_noindex_urls',
                'product_surface.llms_full_404_url_count',
                'product_surface.llms_full_404_urls',
                'product_surface.llms_full_redirect_source_url_count',
                'product_surface.llms_full_redirect_source_urls',
                'llms_full.noindex_url_count',
                'llms_full.noindex_urls',
                'llms_full.not_found_url_count',
                'llms_full.404_urls',
                'llms_full.redirect_source_url_count',
                'llms_full.redirect_source_urls',
                'forbidden_exposure.llms_full_noindex_urls',
                'forbidden_exposure.llms_full_404_urls',
                'forbidden_exposure.llms_full_redirect_source_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.llms_full_noindex_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.llms_full_404_urls',
                'validation.full_visible_publication_gate.forbidden_exposure_counts.llms_full_redirect_source_urls',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
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
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $paths
     */
    private function countAtAny(array $payload, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_numeric($value)) {
                return (int) $value;
            }
            if (is_array($value) && array_is_list($value)) {
                return count($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $paths
     */
    private function hasAny(array $payload, array $paths): bool
    {
        foreach ($paths as $path) {
            if (data_get($payload, $path) !== null) {
                return true;
            }
        }

        return false;
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
}
