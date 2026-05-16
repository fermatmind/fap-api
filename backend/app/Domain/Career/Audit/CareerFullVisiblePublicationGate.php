<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerFullVisiblePublicationGate
{
    public function appliesTo(int $targetPublicTotal): bool
    {
        return $targetPublicTotal === 2786;
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
            ]),
            'found_published_locale_rows' => $this->foundPublishedLocaleRows($liveAcceptance),
            'release_gate_pass_count' => $this->releaseGatePassCount($liveAcceptance),
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
            'collection_summary.public_detail_indexable_count',
            'api_collection_summary.public_detail_indexable_count',
            'public_detail_indexable_count',
            'observed_detail_ready_count',
        ]) ?? $this->detailReadyCount($payload);
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
        ]);
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
